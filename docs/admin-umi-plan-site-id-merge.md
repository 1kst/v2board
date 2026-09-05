# 管理员 UMI：站点归属补丁合并说明

## 用途

本文件记录对管理员后台编译文件 `public/assets/admin/umi.js` 的站点归属修改。

功能效果：

- “订阅管理”列表新增“归属站点”列，只显示套餐的 `site_id` 数字。
- 点击“添加订阅”时新增“归属站点”下拉框，只显示 `1`、`2`、`3`。
- 该选择会随新套餐保存请求提交给后端。
- 公告和知识库教程支持选择一个或多个站点，并在各自列表中显示已选站点 ID。

> 注意：这是管理员后台的已编译 UMI 文件，不是用户前端的
> `public/theme/default/assets/umi.js`，也不是 EZNEXT 的源码。

## 必须同时保留的后端修改

只合并本文件的 UMI 改动只能显示界面，不能可靠保存套餐归属。上游更新后还必须保留：

- `app/Http/Requests/Admin/PlanSave.php`
  - 接收并校验可选的 `site_id`，限定为 `config/sites.php` 内已定义的站点 ID。
- `app/Http/Controllers/V1/Admin/PlanController.php`
  - 新建套餐时写入提交的 `site_id`；旧版后台未提交该字段时回退到请求站点 ID。
  - 编辑已有套餐时丢弃 `site_id`，禁止将已有套餐迁移到另一个站点。
  - 套餐列表使用 `Plan::withoutGlobalScopes()`，管理员可见三个站点的套餐。

后端保存逻辑是归属隔离的关键；用户前端不应再配置 `FILTER_CONFIG.planIds`，保持空数组即可。

## 合并前准备

1. 先备份上游更新后的 `public/assets/admin/umi.js`。
2. 用以下命令定位真实的“订阅管理”组件。文件内可能有其他 `site_id`，例如用户管理页面；不要误改它们。

```powershell
rg -n -F 'type: "plan/save"' public/assets/admin/umi.js
rg -n -F 'title: "\u8ba2\u9605\u7ba1\u7406"' public/assets/admin/umi.js
rg -n -F 'dataIndex: "name"' public/assets/admin/umi.js
```

3. 确认命中的组件同时含有 `plan/fetch`、`plan/save`、`添加订阅` 或 `新建订阅`。以下代码中的变量名来自当前 UMI 构建；上游重新编译后变量名可能变化，应按功能结构合入。

## UMI 的三处修改

### 1. 新建订阅的默认归属站点

在订阅表单组件的 `record` 默认对象中加入：

```js
site_id: 1,
```

当前上下文：

```js
record: e.record || {
    show: 0,
    site_id: 1,
    name: null,
```

这样旧版保存逻辑或管理员误操作不会创建没有归属的套餐。

### 2. “添加订阅”表单的归属站点下拉框

在“套餐描述”输入框结束后、售价设置分隔线之前插入下列 React 元素。条件 `this.state.record.id ? null : ...` 很重要：只允许新建时选择，编辑已有套餐时不允许改归属。

```js
this.state.record.id ? null : m.a.createElement("div", {
    className: "form-group"
}, m.a.createElement("label", {
    htmlFor: "plan-site-id"
}, "\\u5f52\\u5c5e\\u7ad9\\u70b9"), m.a.createElement(_["a"], {
    id: "plan-site-id",
    style: { width: "100%" },
    value: this.state.record.site_id || 1,
    onChange: e=>{
        this.setState({
            record: d()({}, this.state.record, { site_id: e })
        })
    }
}, m.a.createElement(_["a"].Option, { value: 1 }, "1"),
   m.a.createElement(_["a"].Option, { value: 2 }, "2"),
   m.a.createElement(_["a"].Option, { value: 3 }, "3")))
```

这里的 `_` 是当前 UMI 中的 Select 组件，`d` 是对象合并函数；如果上游 bundle 的变量名变化，复用同一组件附近已有的 Select 和 `setState` 写法。

### 3. 订阅列表的“归属站点”列

在订阅管理的 columns 数组内、名称列之后插入：

```js
{
    title: "\\u5f52\\u5c5e\\u7ad9\\u70b9",
    dataIndex: "site_id",
    key: "site_id",
    render: e=>m.a.createElement(l["a"], null, e || 1)
},
```

当前前后文：

```js
{
    title: "\\u540d\\u79f0",
    dataIndex: "name",
    key: "name"
}, {
    title: "\\u5f52\\u5c5e\\u7ad9\\u70b9",
    dataIndex: "site_id",
    key: "site_id",
    render: e=>m.a.createElement(l["a"], null, e || 1)
}, {
    title: "\\u7edf\\u8ba1",
```

不要使用 `site_name`、`春秋X（ID: 1）` 等文字。当前需求是仅显示 ID 数字。

## 验证与部署

1. 语法检查：

```bash
node --check public/assets/admin/umi.js
php -l app/Http/Requests/Admin/PlanSave.php
php -l app/Http/Controllers/V1/Admin/PlanController.php
```

2. 覆盖服务器上同路径的 `umi.js`、`PlanSave.php`、`PlanController.php`。
3. 清理 Laravel 编译视图：

```bash
php artisan view:clear
```

4. 如果 Cloudflare 缓存了带 `?v=<版本号>` 的旧 UMI 文件，请在 **后端域名所在的 Cloudflare 区域** 清理：

```text
/assets/admin/umi.js?v=<当前版本号>
```

5. 后台验证：

- “订阅管理”列表出现“归属站点”列，旧套餐分别显示 `1`、`2`、`3`。
- 点击“添加订阅”，下拉框只显示 `1`、`2`、`3`。
- 选择 `2` 创建测试套餐后，访问机长前端可见，访问春秋与易加速前端不可见。
- 编辑该套餐时不出现归属站点下拉框。

## 不要改动

- 不要在用户前端 `FILTER_CONFIG.planIds` 中维护三个站点套餐 ID；它应保持 `[]`。
- 不要用管理员请求携带的 `X-Site-ID` 覆盖已有套餐的 `site_id`。
- 不要把本补丁套用到 `public/theme/default/assets/umi.js`；该文件是用户端主题，和管理员后台无关。

## 公告与知识库教程：多选归属站点

套餐只能属于一个站点，因此使用 `v2_plan.site_id`。公告和教程需要同时在多个站点展示，不能复用这个字段；使用两张关联表：

| 内容 | 关联表 | 外键 | 站点字段 |
| --- | --- | --- | --- |
| 公告 | `v2_notice_site` | `notice_id` | `site_id` |
| 教程 | `v2_knowledge_site` | `knowledge_id` | `site_id` |

关联表使用“内容 ID + 站点 ID”的联合主键。因此只选一个 ID 就是单选，勾选多个 ID 就是多选；没有任何站点的内容不会通过用户接口返回。

### 必须同时合并的后端文件

- `app/Services/ContentSiteService.php`：内容关联查询、批量读取、同步多选关系。
- `database/migrations/2026_09_05_000001_create_content_site_assignments.php`：创建两张关联表，并把更新前的公告、教程回填至站点 `1`。
- `app/Http/Controllers/V1/User/NoticeController.php`
- `app/Http/Controllers/V1/User/KnowledgeController.php`
  - 使用请求的 `X-Site-ID` 筛选关联表；文章详情 URL 直接带 ID 也不能跨站读取。
- `app/Http/Controllers/V1/Admin/NoticeController.php`
- `app/Http/Controllers/V1/Admin/KnowledgeController.php`
- `app/Http/Controllers/V1/Staff/NoticeController.php`
  - 管理后台读取所有内容并附加 `site_ids`，保存时同步关联表。
- `app/Http/Requests/Admin/NoticeSave.php`
- `app/Http/Requests/Admin/KnowledgeSave.php`
  - 校验 `site_ids` 为至少一个、且仅含配置中存在的站点 ID。

### 管理员 UMI 修改点

公告和教程都使用 Ant Design Select 的 `mode: "multiple"`，选项文字必须仅显示 `1`、`2`、`3`。

#### 公告管理

在公告编辑弹窗中、图片 URL 字段之前增加：

```js
g.a.createElement(a["a"], {
    mode: "multiple",
    value: this.state.submit.site_ids || [1],
    onChange: e=>{
        this.setState({
            submit: p()({}, this.state.submit, { site_ids: e })
        })
    }
}, g.a.createElement(a["a"].Option, { value: 1 }, "1"),
   g.a.createElement(a["a"].Option, { value: 2 }, "2"),
   g.a.createElement(a["a"].Option, { value: 3 }, "3"))
```

并在公告列表标题列后新增：

```js
{
    title: "\\u5f52\\u5c5e\\u7ad9\\u70b9",
    dataIndex: "site_ids",
    key: "site_ids",
    render: e=>g.a.createElement("span", null, e && e.length ? e.join(", ") : "1")
},
```

#### 知识库管理

在教程编辑抽屉中、语言字段之后增加相同的多选控件。当前 bundle 中对应变量是 `b["a"]` 和 `f.a`：

```js
f.a.createElement(b["a"], {
    mode: "multiple",
    value: n.site_ids || [1],
    onChange: e=>this.formChange("site_ids", e)
}, f.a.createElement(b["a"].Option, { value: 1 }, "1"),
   f.a.createElement(b["a"].Option, { value: 2 }, "2"),
   f.a.createElement(b["a"].Option, { value: 3 }, "3"))
```

在教程列表“文章 ID”列后增加：

```js
{
    title: "\\u5f52\\u5c5e\\u7ad9\\u70b9",
    dataIndex: "site_ids",
    key: "site_ids",
    render: e=>f.a.createElement("span", null, e && e.length ? e.join(", ") : "1")
},
```

### 部署顺序

1. 部署 PHP 文件和 `umi.js`。
2. 执行迁移；若旧环境没有 Laravel `migrations` 表，不要执行所有历史 migration，只执行本迁移的 `up()` 或等价 SQL。
3. 清理 Cloudflare 中后端域名的 `/assets/admin/umi.js?v=<当前版本号>` 缓存。
4. 验证一条公告和一篇教程分别选择 `2, 3` 后：站点 1 不可见，站点 2 与 3 可见；编辑后保留已选 ID。
