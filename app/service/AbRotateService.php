<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;

/**
 * A/B 二级域名轮换 + 联动容灾
 *
 * 设计说明：
 * - 不再依赖本地解析记录表，而是直接依赖 dmtask 里的记录信息 + DNS 接口。
 * - abrotate 表只需要知道：哪个域名、A/B 两个容灾任务 ID、当前轮到哪个槽位。
 */
class AbRotateService
{
    /**
     * 执行所有启用的轮换任务
     */
    public function execute()
    {
        $list = Db::name('abrotate')->where('active', 1)->select();
        if (count($list) === 0) {
            return false;
        }
        echo '开始执行 A/B 轮换任务，共 ' . count($list) . " 个\n";

        foreach ($list as $row) {
            try {
                $this->executeOne($row);
                echo '轮换任务 ' . $row['id'] . " 执行成功\n";
            } catch (Exception $e) {
                echo '轮换任务 ' . $row['id'] . ' 执行失败: ' . $e->getMessage() . "\n";
            }
        }
        return true;
    }

    /**
     * 执行单个 A/B 轮换
     *
     * @param array $row abrotate 表的一行
     * @throws Exception
     */
    private function executeOne(array $row)
    {
        // 获取域名 + 账号信息
        $drow = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')
            ->where('A.id', $row['did'])
            ->field('A.*,B.type,B.config')
            ->find();
        if (!$drow) {
            throw new Exception('域名不存在（did=' . $row['did'] . '）');
        }

        $dns = DnsHelper::getModel2($drow);
        if (!$dns) {
            throw new Exception('获取 DNS 模型失败');
        }

        // 当前要操作的槽位（A 或 B），默认为 A
        $slot = $row['current_slot'] === 'B' ? 'B' : 'A';

        // 取对应的容灾任务（dmtask）
        $taskId = $slot === 'A' ? $row['task_a_id'] : $row['task_b_id'];
        $task = Db::name('dmtask')->where('id', $taskId)->find();
        if (!$task) {
            throw new Exception('容灾任务不存在（task_id=' . $taskId . '）');
        }

        $recordId = $task['recordid'];
        $oldRr = $task['rr'];

        // 通过 DNS 接口获取当前记录详情（类型 / 值 / 线路 / TTL 等）
        $recordInfo = $dns->getDomainRecordInfo($recordId);
        if (!$recordInfo) {
            throw new Exception('获取解析记录信息失败：' . $dns->getError());
        }

        // 生成新的随机主机记录
        $prefix = empty($row['prefix']) ? 'cs' : $row['prefix'];
        $rand = substr(md5(uniqid('', true)), 0, 6);
        $newRr = $prefix . $rand;

        // 使用当前记录的类型 / 值 / 线路 / TTL，仅修改主机记录（RR）
        $type = $recordInfo['Type'];
        $value = $recordInfo['Value'];
        $line = $recordInfo['Line'];
        $ttl = $recordInfo['TTL'];
        $mx = $recordInfo['MX'] ?? 1;
        $weight = $recordInfo['Weight'] ?? null;
        $remark = $recordInfo['Remark'] ?? null;

        $res = $dns->updateDomainRecord($recordId, $newRr, $type, $value, $line, $ttl, $mx, $weight, $remark);
        if (!$res) {
            throw new Exception('DNS 更新失败：' . $dns->getError());
        }

        // 更新 dmtask 中的 rr 和 recordinfo，保证容灾任务后续使用新的主机记录
        // recordinfo 字段长度只有 200，这里只保留任务执行所需的最小字段
        $miniRecordInfo = [
            'Line' => $line,
            'TTL'  => $ttl,
        ];
        Db::name('dmtask')->where('id', $taskId)->update([
            'rr' => $newRr,
            'recordinfo' => json_encode($miniRecordInfo, JSON_UNESCAPED_UNICODE),
        ]);

        // 切换下次要轮换的槽位
        $nextSlot = $slot === 'A' ? 'B' : 'A';
        Db::name('abrotate')->where('id', $row['id'])->update(['current_slot' => $nextSlot]);

        // 固定二级域名：将其 CNAME 指向本次生成的最新随机域名（重新查库确保 fixed_rr 为最新）
        $cfg = Db::name('abrotate')->where('id', $row['id'])->field('fixed_rr')->find();
        $fixedRr = isset($cfg['fixed_rr']) ? trim((string) $cfg['fixed_rr']) : '';
        $this->updateFixedCname($dns, $drow, $fixedRr, $newRr);

        // 写操作日志
        $fullDomainOld = $oldRr . '.' . $drow['name'];
        $fullDomainNew = $newRr . '.' . $drow['name'];
        Db::name('log')->insert([
            'uid' => 0,
            'domain' => $drow['name'],
            'action' => 'A/B 轮换',
            'data' => "槽{$slot}: {$fullDomainOld} -> {$fullDomainNew}",
            'addtime' => date("Y-m-d H:i:s"),
        ]);
    }

    /**
     * 将固定二级域名的 CNAME 记录指向最新随机域名（如 pay.example.com -> cs3f9a1b.example.com）
     * 若 fixed_rr 未配置或为空则不处理；若尚无 CNAME 则自动创建一条
     *
     * @param \app\lib\DnsInterface $dns
     * @param array $drow 域名行（含 name、type）
     * @param string $fixedRr 固定主机记录，如 pay
     * @param string $newRr 本次轮换得到的新主机记录，如 cs3f9a1b
     */
    private function updateFixedCname($dns, $drow, $fixedRr, $newRr)
    {
        $fixedRr = trim((string) $fixedRr);
        if ($fixedRr === '') {
            return;
        }

        $target = $newRr . '.' . $drow['name'];

        $list = $dns->getSubDomainRecords($fixedRr, 1, 20);
        if (!$list || empty($list['list'])) {
            // 尚无记录，创建一条 CNAME
            $dnstype = $drow['type'] ?? '';
            $lineDef = isset(DnsHelper::$line_name[$dnstype]['DEF']) ? DnsHelper::$line_name[$dnstype]['DEF'] : '0';
            $dns->addDomainRecord($fixedRr, 'CNAME', $target, $lineDef, 600, 1, null, null);
            $this->logFixedCname($drow['name'], $fixedRr, $target, '创建');
            return;
        }

        $cnameRecord = null;
        foreach ($list['list'] as $r) {
            $t = is_array($r['Type']) ? $r['Type'][0] ?? '' : $r['Type'];
            if (strtoupper((string) $t) === 'CNAME') {
                $cnameRecord = $r;
                break;
            }
        }

        if ($cnameRecord) {
            $recordId = $cnameRecord['RecordId'];
            $line = $cnameRecord['Line'] ?? (DnsHelper::$line_name[$drow['type'] ?? '']['DEF'] ?? '0');
            $ttl = $cnameRecord['TTL'] ?? 600;
            $mx = $cnameRecord['MX'] ?? 1;
            $weight = $cnameRecord['Weight'] ?? null;
            $remark = $cnameRecord['Remark'] ?? null;
            $dns->updateDomainRecord($recordId, $fixedRr, 'CNAME', $target, $line, $ttl, $mx, $weight, $remark);
            $this->logFixedCname($drow['name'], $fixedRr, $target, '更新');
        } else {
            // 该子域下没有 CNAME，新建一条
            $lineDef = isset(DnsHelper::$line_name[$drow['type'] ?? '']['DEF']) ? DnsHelper::$line_name[$drow['type']]['DEF'] : '0';
            $dns->addDomainRecord($fixedRr, 'CNAME', $target, $lineDef, 600, 1, null, null);
            $this->logFixedCname($drow['name'], $fixedRr, $target, '创建');
        }
    }

    private function logFixedCname($domain, $fixedRr, $target, $action)
    {
        $fullFixed = $fixedRr . '.' . $domain;
        Db::name('log')->insert([
            'uid' => 0,
            'domain' => $domain,
            'action' => '固定CNAME',
            'data' => "{$action} {$fullFixed} -> {$target}",
            'addtime' => date("Y-m-d H:i:s"),
        ]);
    }
}

