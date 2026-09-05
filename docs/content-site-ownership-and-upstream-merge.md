# 三站公告与教程归属，以及上游更新合并说明

本文记录三站合一后，公告（`v2_notice`）和教程（`v2_knowledge`）的归属实现、原始数据导入办法，以及后续同步 V2Board 上游时必须保留的改动。

## 站点编号

| 原站点 | site_id |
| --- | --- |
| chunqiux（主站） | 1 |
| jichang | 2 |
| yzjiasu | 3 |

一个公告或教程可同时分配到多个站点。管理员界面和接口只显示数字 ID，以免站点名称变化影响数据。

## 数据库设计

没有给 V2Board 上游的 `v2_notice`、`v2_knowledge` 主表加字段；归属关系单独放在两个映射表中，因此不会与上游主表结构冲突：

```text
v2_notice         1 --- n v2_notice_site   (notice_id, site_id)
v2_knowledge      1 --- n v2_knowledge_site (knowledge_id, site_id)
```

迁移文件：`database/migrations/2026_09_05_000001_create_content_site_assignments.php`。

首次运行迁移会把当时已有内容暂时标成 site_id=1，避免内容在迁移瞬间消失。随后必须按下方“原始数据归属导入”执行一次，才能恢复三站原有归属。

## 已修改的后端位置

| 文件 | 作用 |
| --- | --- |
| `app/Services/ContentSiteService.php` | 归属 ID 校验、映射查询、保存同步的共用服务。 |
| `app/Http/Controllers/V1/User/NoticeController.php` | 用户端公告列表和详情按请求 `site_id` 过滤。 |
| `app/Http/Controllers/V1/User/KnowledgeController.php` | 用户端教程列表和详情按请求 `site_id` 过滤。 |
| `app/Http/Controllers/V1/Admin/NoticeController.php` | 管理员公告列表返回 `site_ids`；新增、编辑、删除同步映射。 |
| `app/Http/Controllers/V1/Admin/KnowledgeController.php` | 管理员教程列表返回 `site_ids`；新增、编辑、删除同步映射。 |
| `app/Http/Controllers/V1/Staff/NoticeController.php` | 员工端公告管理同步返回及保存归属。 |
| `app/Http/Requests/Admin/NoticeSave.php` | 校验 `site_ids` 必须为 1、2、3 中至少一项。 |
| `app/Http/Requests/Admin/KnowledgeSave.php` | 校验 `site_ids` 必须为 1、2、3 中至少一项。 |
| `database/install.sql` | 全新安装时创建两个映射表。 |
| `public/assets/admin/umi.js` | 管理后台公告、教程列表显示“归属站点”；编辑时用多选框选择 1/2/3。 |

用户接口仍由现有站点识别中间件写入请求 `site_id`；前端使用对应的 `X-Site-ID` / key 调用后端即可。一个站点看不到不属于它的公告或教程；详情接口也会再次过滤，不能只凭文章 ID 跨站读取。

## 原始数据归属导入

三份原始 MySQL 备份是数据来源，不能按旧数据库的自增 ID 直接写入主库，因为三个库的 `id` 会冲突。导入器按内容的稳定字段计算指纹：

- 公告：标题、正文、图片、标签；
- 教程：语言、分类、标题、正文。

规则如下：

1. 与主库已有内容完全一致的记录，不重复插入，只补齐它所属的 site_id。
2. 多个原站内容完全一致时，保留一条内容记录，并分配多个 site_id。
3. 主库缺失的原站内容会新增一条记录，并保留原始的显示状态、排序和创建/更新时间。
4. 当前主库中找不到任何原始来源的内容不删除，维持现状，避免误删管理员后来新增的内容。

先在有 Node.js 的电脑生成 JSON（不要把 JSON 提交到 Git）：

```powershell
node .\extract-content-site-data.js `
  --site 1 --dump 'C:\backup\chunqiux_mysql_data.sql.gz' `
  --site 2 --dump 'C:\backup\jichang_mysql_data.sql.gz' `
  --site 3 --dump 'C:\backup\yzjiasu_mysql_data.sql.gz' `
  --output .\content-site-data.json
```

将 `content-site-data.json` 上传到 V2Board 根目录后，先只查看计划：

```bash
php import-content-site-data.php /path/to/content-site-data.json
```

确认输出的数量正确，再真正写入：

```bash
php import-content-site-data.php /path/to/content-site-data.json --apply
```

导入器是幂等的：对同一份 JSON 重复执行不会重复插入匹配内容，只会重建相应内容的映射关系。

## 验证 SQL

```sql
SELECT site_id, COUNT(*) AS notice_count
FROM v2_notice_site
GROUP BY site_id
ORDER BY site_id;

SELECT site_id, COUNT(*) AS knowledge_count
FROM v2_knowledge_site
GROUP BY site_id
ORDER BY site_id;

SELECT n.id, n.title, GROUP_CONCAT(ns.site_id ORDER BY ns.site_id) AS site_ids
FROM v2_notice AS n
JOIN v2_notice_site AS ns ON ns.notice_id = n.id
GROUP BY n.id, n.title
ORDER BY n.id DESC;

SELECT k.id, k.title, GROUP_CONCAT(ks.site_id ORDER BY ks.site_id) AS site_ids
FROM v2_knowledge AS k
JOIN v2_knowledge_site AS ks ON ks.knowledge_id = k.id
GROUP BY k.id, k.title
ORDER BY k.id DESC;
```

还应分别以 site_id=1、2、3 的前端登录并检查：公告列表、教程分类、教程详情，以及管理员新增/编辑时的多选归属。

## 后续合并 V2Board 上游更新

1. 先拉取上游并在单独分支处理冲突，不要覆盖本仓库的 `master`。
2. 保留本文件“已修改的后端位置”表中的改动；上游若也修改了同一个控制器，保留其业务更新，再把 `ContentSiteService` 的按站点过滤与 `site_ids` 同步逻辑合回去。
3. 保留迁移、`database/install.sql` 中的两张映射表，以及 `ContentSiteService.php`。
4. `public/assets/admin/umi.js` 是构建产物。上游替换后台资产后，需要重新合入公告和教程的 `site_ids` 列及多选字段，再部署新文件并清理 CDN 对该文件的缓存。
5. 合并后先执行本文件的 SQL 验证；不要重跑原始数据导入，除非源备份有变更。

这些改动只新增映射表，不修改上游公告和教程主表字段，因此上游数据库迁移通常不会直接冲突。
