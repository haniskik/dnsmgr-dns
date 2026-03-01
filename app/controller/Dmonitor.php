<?php

namespace app\controller;

use app\BaseController;
    use think\facade\Db;
    use think\facade\View;
    use think\facade\Cache;

    class Dmonitor extends BaseController
    {
        public function overview()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $switch_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
            $fail_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

            $run_time = config_get('run_time', null, true);
            $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
            View::assign('info', [
                'run_count' => config_get('run_count', null, true) ?? 0,
                'run_time' => $run_time ?? '无',
                'run_state' => $run_state,
                'run_error' => config_get('run_error', null, true),
                'switch_count' => $switch_count,
                'fail_count' => $fail_count,
                'swoole' => extension_loaded('swoole') ? '<font color="green">已安装</font>' : '<font color="red">未安装</font>',
            ]);
            return View::fetch();
        }

        public function task()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            return View::fetch();
        }

        public function task_data()
        {
            if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
            $type = input('post.type/d', 1);
            $status = input('post.status', null);
            $kw = input('post.kw', null, 'trim');
            $offset = input('post.offset/d');
            $limit = input('post.limit/d');

            $select = Db::name('dmtask')->alias('A')->join('domain B', 'A.did = B.id');
            if (!empty($kw)) {
                if ($type == 1) {
                    $select->whereLike('rr|B.name', '%' . $kw . '%');
                } elseif ($type == 2) {
                    $select->where('recordid', $kw);
                } elseif ($type == 3) {
                    $select->where('main_value', $kw);
                } elseif ($type == 4) {
                    $select->where('backup_value', $kw);
                } elseif ($type == 5) {
                    $select->whereLike('remark', '%' . $kw . '%');
                }
            }
            if (!isNullOrEmpty($status)) {
                $select->where('status', intval($status));
            }
            $total = $select->count();
            $list = $select->order('A.id', 'desc')->limit($offset, $limit)->field('A.*,B.name domain')->select()->toArray();

            foreach ($list as &$row) {
                $row['addtimestr'] = date('Y-m-d H:i:s', $row['addtime']);
                $row['checktimestr'] = $row['checktime'] > 0 ? date('Y-m-d H:i:s', $row['checktime']) : '未运行';
            }

            return json(['total' => $total, 'rows' => $list]);
        }

        public function task_op()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $action = input('param.action');
            if ($action == 'add') {
                $task = [
                    'did' => input('post.did/d'),
                    'rr' => input('post.rr', null, 'trim'),
                    'recordid' => input('post.recordid', null, 'trim'),
                    'type' => input('post.type/d'),
                    'main_value' => input('post.main_value', null, 'trim'),
                    'backup_value' => input('post.backup_value', null, 'trim'),
                    'checktype' => input('post.checktype/d'),
                    'checkurl' => input('post.checkurl', null, 'trim'),
                    'tcpport' => !empty(input('post.tcpport')) ? input('post.tcpport/d') : null,
                    'frequency' => input('post.frequency/d'),
                    'cycle' => input('post.cycle/d'),
                    'timeout' => input('post.timeout/d'),
                    'proxy' => input('post.proxy/d'),
                    'cdn' => input('post.cdn') == 'true' || input('post.cdn') == '1' ? 1 : 0,
                    'remark' => input('post.remark', null, 'trim'),
                    'recordinfo' => input('post.recordinfo', null, 'trim'),
                    'addtime' => time(),
                    'active' => 1
                ];

                if (empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])) {
                    return json(['code' => -1, 'msg' => '必填项不能为空']);
                }
                if ($task['checktype'] > 0 && $task['timeout'] > $task['frequency']) {
                    return json(['code' => -1, 'msg' => '为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
                }
                if ($task['type'] == 2 && $task['backup_value'] == $task['main_value']) {
                    return json(['code' => -1, 'msg' => '主备地址不能相同']);
                }
                if (Db::name('dmtask')->where('recordid', $task['recordid'])->find()) {
                    return json(['code' => -1, 'msg' => '当前容灾切换策略已存在']);
                }
                Db::name('dmtask')->insert($task);
                return json(['code' => 0, 'msg' => '添加成功']);
            } elseif ($action == 'edit') {
                $id = input('post.id/d');
                $task = [
                    'did' => input('post.did/d'),
                    'rr' => input('post.rr', null, 'trim'),
                    'recordid' => input('post.recordid', null, 'trim'),
                    'type' => input('post.type/d'),
                    'main_value' => input('post.main_value', null, 'trim'),
                    'backup_value' => input('post.backup_value', null, 'trim'),
                    'checktype' => input('post.checktype/d'),
                    'checkurl' => input('post.checkurl', null, 'trim'),
                    'tcpport' => !empty(input('post.tcpport')) ? input('post.tcpport/d') : null,
                    'frequency' => input('post.frequency/d'),
                    'cycle' => input('post.cycle/d'),
                    'timeout' => input('post.timeout/d'),
                    'proxy' => input('post.proxy/d'),
                    'cdn' => input('post.cdn') == 'true' || input('post.cdn') == '1' ? 1 : 0,
                    'remark' => input('post.remark', null, 'trim'),
                    'recordinfo' => input('post.recordinfo', null, 'trim'),
                ];

                if (empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])) {
                    return json(['code' => -1, 'msg' => '必填项不能为空']);
                }
                if ($task['checktype'] > 0 && $task['timeout'] > $task['frequency']) {
                    return json(['code' => -1, 'msg' => '为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
                }
                if ($task['type'] == 2 && $task['backup_value'] == $task['main_value']) {
                    return json(['code' => -1, 'msg' => '主备地址不能相同']);
                }
                if (Db::name('dmtask')->where('recordid', $task['recordid'])->where('id', '<>', $id)->find()) {
                    return json(['code' => -1, 'msg' => '当前容灾切换策略已存在']);
                }
                Db::name('dmtask')->where('id', $id)->update($task);
                return json(['code' => 0, 'msg' => '修改成功']);
            } elseif ($action == 'setactive') {
                $id = input('post.id/d');
                $active = input('post.active/d');
                Db::name('dmtask')->where('id', $id)->update(['active' => $active]);
                return json(['code' => 0, 'msg' => '设置成功']);
            } elseif ($action == 'del') {
                $id = input('post.id/d');
                Db::name('dmtask')->where('id', $id)->delete();
                Db::name('dmlog')->where('taskid', $id)->delete();
                return json(['code' => 0, 'msg' => '删除成功']);
            } elseif ($action == 'operation') {
                $ids = input('post.ids');
                $success = 0;
                foreach ($ids as $id) {
                    if (input('post.act') == 'delete') {
                        Db::name('dmtask')->where('id', $id)->delete();
                        Db::name('dmlog')->where('taskid', $id)->delete();
                        $success++;
                    } elseif (input('post.act') == 'retry') {
                        Db::name('dmtask')->where('id', $id)->update(['checknexttime' => time()]);
                        $success++;
                    } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                        $isauto = input('post.act') == 'open' ? 1 : 0;
                        Db::name('dmtask')->where('id', $id)->update(['active' => $isauto]);
                        $success++;
                    }
                }
                return json(['code' => 0, 'msg' => '成功操作' . $success . '个容灾切换策略']);
            } else {
                return json(['code' => -1, 'msg' => '参数错误']);
            }
        }

        public function taskform()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $action = input('param.action');
            $task = null;
            if ($action == 'edit') {
                $id = input('get.id/d');
                $task = Db::name('dmtask')->where('id', $id)->find();
                if (empty($task)) return $this->alert('error', '切换策略不存在');
            }

            $domains = [];
            $domainList = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->field('A.id,A.name,B.type')->select();
            foreach ($domainList as $row) {
                $domains[] = ['id'=>$row['id'], 'name'=>$row['name'], 'type'=>$row['type']];
            }
            View::assign('domains', $domains);

            View::assign('info', $task);
            View::assign('action', $action);
            View::assign('support_ping', function_exists('exec') ? '1' : '0');
            return View::fetch();
        }

        public function taskinfo()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('param.id/d');
            $task = Db::name('dmtask')->where('id', $id)->find();
            if (empty($task)) return $this->alert('error', '切换策略不存在');

            $switch_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
            $fail_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

            $task['switch_count'] = $switch_count;
            $task['fail_count'] = $fail_count;
            if ($task['type'] == 3) {
                $task['action_name'] = ['未知', '<font color="red">开启解析</font>', '<font color="green">暂停解析</font>'];
            } elseif ($task['type'] == 2) {
                $task['action_name'] = ['未知', '<font color="red">切换备用解析记录</font>', '<font color="green">恢复主解析记录</font>'];
            } else {
                $task['action_name'] = ['未知', '<font color="red">暂停解析</font>', '<font color="green">启用解析</font>'];
            }
            View::assign('info', $task);
            return View::fetch();
        }

        public function tasklog_data()
        {
            if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
            $taskid = input('param.id/d');
            $offset = input('post.offset/d');
            $limit = input('post.limit/d');
            $action = input('post.action/d', 0);

            $select = Db::name('dmlog')->where('taskid', $taskid);
            if ($action > 0) {
                $select->where('action', $action);
            }
            $total = $select->count();
            $list = $select->order('id', 'desc')->limit($offset, $limit)->select();

            return json(['total' => $total, 'rows' => $list]);
        }

        public function clean()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            if ($this->request->isPost()) {
                $days = input('post.days/d');
                if (!$days || $days < 0) return json(['code' => -1, 'msg' => '参数错误']);
                Db::execute("DELETE FROM `" . config('database.connections.mysql.prefix') . "dmlog` WHERE `date`<'" . date("Y-m-d H:i:s", strtotime("-" . $days . " days")) . "'");
                Db::execute("OPTIMIZE TABLE `" . config('database.connections.mysql.prefix') . "dmlog`");
                return json(['code' => 0, 'msg' => '清理成功']);
            }
        }

        public function status()
        {
            $run_time = config_get('run_time', null, true);
            $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
            return $run_state == 1 ? 'ok' : 'error';
        }

        /**
         * A/B 轮换配置列表
         */
        public function abrotate()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $domains = Db::name('domain')->field('id,name')->select()->toArray();
            View::assign('domains', $domains);
            return View::fetch();
        }

        /**
         * A/B 轮换配置列表数据
         */
        public function abrotate_data()
        {
            if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
            $offset = input('post.offset/d', 0);
            $limit = input('post.limit/d', 10);
            $select = Db::name('abrotate')->alias('A')->join('domain B', 'A.did = B.id')
                ->field('A.*,B.name domain');
            $total = $select->count();
            $list = Db::name('abrotate')->alias('A')->join('domain B', 'A.did = B.id')
                ->field('A.*,B.name domain')
                ->order('A.id', 'desc')
                ->limit($offset, $limit)
                ->select()
                ->toArray();
            foreach ($list as &$row) {
                $row['fixed_domain'] = $row['fixed_rr'] !== '' && $row['fixed_rr'] !== null
                    ? $row['fixed_rr'] . '.' . $row['domain']
                    : '—';
                $row['active_str'] = $row['active'] ? '启用' : '停用';
            }
            return json(['total' => $total, 'rows' => $list]);
        }

        /**
         * A/B 轮换配置添加页
         */
        public function abrotate_add()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $domains = Db::name('domain')->field('id,name')->select()->toArray();
            View::assign('domains', $domains);
            return View::fetch('abrotateadd');
        }

        /**
         * 按域名获取容灾任务列表（用于添加 A/B 轮换时选择 A 槽/B 槽任务）
         */
        public function abrotate_tasks()
        {
            if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限', 'list' => []]);
            $did = input('get.did/d');
            if (!$did) return json(['code' => 0, 'list' => []]);
            $list = Db::name('dmtask')->where('did', $did)->field('id,rr,recordid,remark')->order('id', 'asc')->select()->toArray();
            foreach ($list as &$row) {
                $row['label'] = $row['rr'] . ' (ID:' . $row['id'] . ')' . ($row['remark'] ? ' - ' . $row['remark'] : '');
            }
            return json(['code' => 0, 'list' => $list]);
        }

        /**
         * A/B 轮换配置编辑页
         */
        public function abrotate_edit()
        {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('get.id/d');
            $row = Db::name('abrotate')->alias('A')->join('domain B', 'A.did = B.id')
                ->where('A.id', $id)
                ->field('A.*,B.name domain')
                ->find();
            if (!$row) return $this->alert('error', '配置不存在');
            View::assign('info', $row);
            return View::fetch('abrotateform');
        }

        /**
         * A/B 轮换配置保存（添加或仅允许修改 fixed_rr、prefix、active）
         */
        public function abrotate_op()
        {
            if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
            $id = input('post.id/d');
            $fixed_rr = input('post.fixed_rr', null, 'trim');
            $prefix = input('post.prefix', null, 'trim');
            $active = input('post.active/d', 1);

            if ($id) {
                $row = Db::name('abrotate')->where('id', $id)->find();
                if (!$row) return json(['code' => -1, 'msg' => '配置不存在']);
                $update = [
                    'fixed_rr' => $fixed_rr === '' ? null : $fixed_rr,
                    'prefix'   => $prefix !== '' ? $prefix : 'cs',
                    'active'   => $active ? 1 : 0,
                ];
                Db::name('abrotate')->where('id', $id)->update($update);
                return json(['code' => 0, 'msg' => '保存成功']);
            }

            $did = input('post.did/d');
            $fixed_rr_input = input('post.fixed_rr', null, 'trim');
            if (!$did || $fixed_rr_input === '' || $fixed_rr_input === null) {
                return json(['code' => -1, 'msg' => '请选择主域名并填写固定业务域名']);
            }
            $exists = Db::name('abrotate')->where('did', $did)->find();
            if ($exists) return json(['code' => -1, 'msg' => '该域名已存在 A/B 轮换配置']);
            $allTasks = Db::name('dmtask')->where('did', $did)->order('id', 'asc')->column('id');
            $count = count($allTasks);
            if ($count === 12) {
                $task_a_ids = implode(',', array_slice($allTasks, 0, 6));
                $task_b_ids = implode(',', array_slice($allTasks, 6, 6));
                Db::name('abrotate')->insert([
                    'did'         => $did,
                    'task_a_id'   => $allTasks[0],
                    'task_b_id'   => $allTasks[6],
                    'task_a_ids'  => $task_a_ids,
                    'task_b_ids'  => $task_b_ids,
                    'current_slot'=> 'A',
                    'prefix'      => $prefix !== '' ? $prefix : 'cs',
                    'fixed_rr'    => $fixed_rr_input,
                    'active'      => $active ? 1 : 0,
                ]);
            } elseif ($count === 2) {
                Db::name('abrotate')->insert([
                    'did'         => $did,
                    'task_a_id'   => $allTasks[0],
                    'task_b_id'   => $allTasks[1],
                    'current_slot'=> 'A',
                    'prefix'      => $prefix !== '' ? $prefix : 'cs',
                    'fixed_rr'    => $fixed_rr_input,
                    'active'      => $active ? 1 : 0,
                ]);
            } else {
                return json(['code' => -1, 'msg' => '该主域名下须有 2 个或 12 个容灾任务（2 个为单对 A/B，12 个为 A 组 1-6、B 组 7-12），当前有 ' . $count . ' 个']);
            }
            return json(['code' => 0, 'msg' => '添加成功']);
        }
    }
