<?php

declare(strict_types=1);

namespace app\command;

use Exception;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Config;
use app\service\AbRotateService;

class Abrotate extends Command
{
    protected function configure()
    {
        $this->setName('abrotate')
            ->setDescription('A/B 二级域名轮换任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
        Config::set($res, 'sys');

        try {
            $output->writeln('开始执行 A/B 轮换任务...');
            (new AbRotateService())->execute();
        } catch (Exception $e) {
            $output->writeln('[Error] ' . $e->getMessage());
        }
    }
}

