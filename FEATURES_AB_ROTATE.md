# A/B 轮换与固定域名 CNAME 联动功能说明

本仓库在 [彩虹聚合DNS管理系统 (dnsmgr)](https://github.com/netcccyun/dnsmgr) 基础上进行二次开发，新增 **每日 A/B 槽随机二级域名轮换** 与 **固定业务域名 CNAME 自动指向最新随机域名** 功能，并与原有 **容灾切换** 联动。

---

## 一、功能概述

| 功能 | 说明 |
|------|------|
| **A/B 槽轮换** | 每日定时将两条解析记录（A 槽 / B 槽）的主机名改为随机生成的二级前缀（如 `cs3f9a1b`），A/B 交替执行，避免解析未生效即切换的问题。 |
| **容灾联动** | 容灾切换任务自动使用当前最新的随机主机记录，无需手动修改容灾策略中的域名。 |
| **固定业务域名** | 可配置一个固定二级域名，每次轮换执行后，其 **CNAME 记录自动更新**为当日最新随机域名，业务方始终访问固定域名即可。 |
| **后台配置页** | 提供「A/B轮换配置」列表与编辑页，可在线修改固定业务域名、随机前缀、启用状态，无需改数据库。 |

---

## 二、使用场景简述

- 主域名：用户添加的域名。
- A 槽解析：原主机记录轮换后变为随机前缀 + 6 位字符。
- B 槽解析：同上，A/B 交替。
- 固定业务域名：可配置固定主机记录（CNAME），每次执行轮换后自动指向本次生成的最新随机域名。
- 容灾：为 A/B 两条解析分别配置容灾任务，系统会随轮换更新容灾任务中的主机记录，实现自动联动。

---

## 三、新增/修改的文件与数据表

### 3.1 数据库

- **表名**：`dnsmgr_abrotate`（表前缀以实际安装为准）
- **用途**：保存 A/B 轮换配置（域名、A/B 容灾任务 ID、当前槽位、随机前缀、固定业务域名、是否启用）。
- **建表 SQL**：见 `app/sql/install.sql` 末尾；升级见 `app/sql/update.sql` 中的 `CREATE TABLE` 与 `ALTER TABLE`。

主要字段说明：

| 字段 | 说明 |
|------|------|
| `did` | 主域名 ID（`dnsmgr_domain.id`） |
| `task_a_id` | A 槽容灾任务 ID（`dnsmgr_dmtask.id`） |
| `task_b_id` | B 槽容灾任务 ID |
| `current_slot` | 下次轮换的槽位：`A` 或 `B` |
| `prefix` | 随机主机名前缀，如 `cs`，最终生成如 `cs`+6 位随机 |
| `fixed_rr` | 固定业务域名的主机记录，如 `pay`，轮换后其 CNAME 指向最新随机域名；为空则不更新 CNAME |
| `active` | 是否启用：1 启用，0 停用 |

### 3.2 后端代码

| 路径 | 说明 |
|------|------|
| `app/service/AbRotateService.php` | A/B 轮换与固定 CNAME 更新逻辑 |
| `app/command/Abrotate.php` | 命令行入口：`php think abrotate` |
| `app/controller/Dmonitor.php` | 新增方法：`abrotate`、`abrotate_data`、`abrotate_edit`、`abrotate_op` |
| `config/console.php` | 注册命令 `abrotate` |

### 3.3 路由与前端

| 路径 | 说明 |
|------|------|
| `route/app.php` | 新增：`/dmonitor/abrotate`、`/dmonitor/abrotate/data`、`/dmonitor/abrotate_edit`、`/dmonitor/abrotate_save` |
| `app/view/common/layout.html` | 侧栏「容灾切换」下增加「A/B轮换配置」菜单 |
| `app/view/dmonitor/abrotate.html` | A/B 轮换配置列表页 |
| `app/view/dmonitor/abrotateform.html` | A/B 轮换配置编辑页（固定域名、前缀、启用） |

---

## 四、使用步骤（简要）

1. **环境**：PHP 8.2+，MySQL，已安装并正常运行 dnsmgr，且已添加 DNS 账户（如阿里云/腾讯云）及主域名。
2. **解析与容灾**：在主域名下添加两条 A 记录（A/B 槽），并在「容灾切换 → 切换策略」中为这两条记录各添加一条容灾任务，记下两个任务 ID。
3. **数据库**：执行 `app/sql/install.sql` 或 `update.sql` 中与 `dnsmgr_abrotate` 相关的建表/加字段语句，并插入一条配置（`did`、`task_a_id`、`task_b_id`、`current_slot`、`prefix`、`fixed_rr`、`active`）。
4. **固定域名**：在 DNS 控制台为该主域名添加一条 CNAME（如主机记录 `pay`），目标可先随意；在「A/B轮换配置」编辑页将「固定业务域名」设为 `pay` 并保存。
5. **定时执行**：在宝塔等计划任务中，每天固定时间（如 11:00）执行：  
   `cd /www/wwwroot/你的站点 && php think abrotate`  
   执行后会自动：轮换当前槽的主机名为随机值、更新容灾任务中的主机记录、将固定域名的 CNAME 指向本次最新随机域名。
6. **查看**：在「操作日志」中可看到「A/B 轮换」与「固定CNAME」记录。

---

## 五、注意事项

- 固定业务域名在 DNS 上需为 **CNAME** 记录；若为 A 记录，需先在 DNS 控制台改为 CNAME 或新增一条 CNAME，再由本功能更新其目标。
- 随机主机名格式：`prefix` + 6 位随机字符（如 `cs` + `3f9a1b`），保证不重复且符合常见主机名规范。
- 本功能与 dnsmgr 原有「容灾切换」「定时切换」并存，互不影响；仅 A/B 轮换配置与固定 CNAME 为二开新增。

---

## 六、开源与致谢

- 基础项目：[彩虹聚合DNS管理系统 (dnsmgr)](https://github.com/netcccyun/dnsmgr)
- 本仓库在保留原项目功能与结构的前提下，仅新增上述 A/B 轮换与固定 CNAME 相关代码与文档。
