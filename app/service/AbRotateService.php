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
     * 执行单个 A/B 轮换（支持单对 task_a_id/task_b_id 或多对 task_a_ids/task_b_ids，如 A 组 1-6、B 组 7-12）
     *
     * @param array $row abrotate 表的一行
     * @throws Exception
     */
    private function executeOne(array $row)
    {
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

        $slot = $row['current_slot'] === 'B' ? 'B' : 'A';
        $prefix = empty($row['prefix']) ? 'cs' : $row['prefix'];

        $taskIds = [];
        $aIds = [];
        $bIds = [];
        if (!empty($row['task_a_ids']) && !empty($row['task_b_ids'])) {
            $aIds = array_map('intval', array_filter(explode(',', (string) $row['task_a_ids'])));
            $bIds = array_map('intval', array_filter(explode(',', (string) $row['task_b_ids'])));
            $taskIds = $slot === 'A' ? $aIds : $bIds;
        }
        if (empty($taskIds)) {
            $allTasks = Db::name('dmtask')->where('did', $row['did'])->order('id', 'asc')->column('id');
            $cnt = count($allTasks);
            // 兼容：
            // - 12 条：1,2,3,4,5,6 为 A 组；7,8,9,10,11,12 为 B 组
            // - 14 条：1,2,3,4,5,6,13 为 A 组；7,8,9,10,11,12,14 为 B 组
            if ($cnt === 12) {
                $aIds = array_slice($allTasks, 0, 6);
                $bIds = array_slice($allTasks, 6, 6);
                $taskIds = $slot === 'A' ? $aIds : $bIds;
            } elseif ($cnt === 14) {
                // allTasks 已按 id 升序：索引 0..13 对应 id 1..14
                $aIds = array_merge(
                    array_slice($allTasks, 0, 6),   // 1..6
                    [$allTasks[12]]                 // 13
                );
                $bIds = array_merge(
                    array_slice($allTasks, 6, 6),   // 7..12
                    [$allTasks[13]]                 // 14
                );
                $taskIds = $slot === 'A' ? $aIds : $bIds;
            } else {
                $singleA = (int) $row['task_a_id'];
                $singleB = (int) $row['task_b_id'];
                if ($singleA > 0) {
                    $aIds = [$singleA];
                }
                if ($singleB > 0) {
                    $bIds = [$singleB];
                }
                $taskIds = $slot === 'A' ? $aIds : $bIds;
            }
        }

        $firstNewRr = null;
        $logParts = [];
        $newRr = null;
        if (count($taskIds) > 1) {
            $rand = substr(md5(uniqid('', true)), 0, 6);
            $newRr = $prefix . $rand;
        }

        foreach ($taskIds as $taskId) {
            $task = Db::name('dmtask')->where('id', $taskId)->find();
            if (!$task) {
                throw new Exception('容灾任务不存在（task_id=' . $taskId . '）');
            }

            $recordId = $task['recordid'];
            $oldRr = $task['rr'];

            $recordInfo = $dns->getDomainRecordInfo($recordId);
            if (!$recordInfo) {
                throw new Exception('获取解析记录信息失败：' . $dns->getError());
            }

            if ($newRr === null) {
                $rand = substr(md5(uniqid('', true) . $taskId), 0, 6);
                $newRr = $prefix . $rand;
            }

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

            $miniRecordInfo = ['Line' => $line, 'TTL' => $ttl];
            Db::name('dmtask')->where('id', $taskId)->update([
                'rr' => $newRr,
                'recordinfo' => json_encode($miniRecordInfo, JSON_UNESCAPED_UNICODE),
            ]);

            if ($firstNewRr === null) {
                $firstNewRr = $newRr;
            }
            $logParts[] = $oldRr . '->' . $newRr;
        }

        // 根据当前槽位，计算本次需要「启用」和需要「暂停」的任务 ID 列表
        $enableTaskIds = $taskIds;
        $disableTaskIds = $slot === 'A' ? $bIds : $aIds;

        // 先暂停另一组解析
        foreach ($disableTaskIds as $disableId) {
            $task = Db::name('dmtask')->where('id', $disableId)->find();
            if (!$task) {
                continue;
            }
            $recordId = $task['recordid'];
            if (empty($recordId)) {
                continue;
            }
            $res = $dns->setDomainRecordStatus($recordId, '0');
            if (!$res) {
                // 不中断整个轮换，只记录错误
                Db::name('log')->insert([
                    'uid' => 0,
                    'domain' => $drow['name'],
                    'action' => '暂停解析失败',
                    'data' => 'task_id=' . $disableId . ' recordid=' . $recordId . ' err=' . $dns->getError(),
                    'addtime' => date("Y-m-d H:i:s"),
                ]);
            }
        }

        // 再启用当前槽位这一组解析
        foreach ($enableTaskIds as $enableId) {
            $task = Db::name('dmtask')->where('id', $enableId)->find();
            if (!$task) {
                continue;
            }
            $recordId = $task['recordid'];
            if (empty($recordId)) {
                continue;
            }
            $res = $dns->setDomainRecordStatus($recordId, '1');
            if (!$res) {
                Db::name('log')->insert([
                    'uid' => 0,
                    'domain' => $drow['name'],
                    'action' => '启用解析失败',
                    'data' => 'task_id=' . $enableId . ' recordid=' . $recordId . ' err=' . $dns->getError(),
                    'addtime' => date("Y-m-d H:i:s"),
                ]);
            }
        }

        $nextSlot = $slot === 'A' ? 'B' : 'A';
        Db::name('abrotate')->where('id', $row['id'])->update(['current_slot' => $nextSlot]);

        $cfg = Db::name('abrotate')->where('id', $row['id'])->field('fixed_rr')->find();
        $fixedRr = isset($cfg['fixed_rr']) ? trim((string) $cfg['fixed_rr']) : '';
        if ($firstNewRr !== null) {
            $this->updateFixedCname($dns, $drow, $fixedRr, $firstNewRr);
        }

        Db::name('log')->insert([
            'uid' => 0,
            'domain' => $drow['name'],
            'action' => 'A/B 轮换',
            'data' => '槽' . $slot . ': ' . implode(', ', $logParts),
            'addtime' => date("Y-m-d H:i:s"),
        ]);
    }

    /**
     * 将固定二级域名的 CNAME 记录指向最新随机域名；若 fixed_rr 未配置或为空则不处理；若尚无 CNAME 则自动创建一条
     *
     * @param \app\lib\DnsInterface $dns
     * @param array $drow 域名行（含 name、type）
     * @param string $fixedRr 固定主机记录
     * @param string $newRr 本次轮换得到的新主机记录
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

