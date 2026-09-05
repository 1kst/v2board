# 三站共用支付方式与隐藏后端回调地址

## 行为

支付方式（`v2_payment`）和订单列表（`v2_order`）不再按 `site_id` 筛选：三个前端共用一组支付渠道，后台只需配置一次。

订单仍保存 `site_id`，但它仅供内部在支付回调、队列开通、余额处理时找回对应的用户和套餐；它不会让支付方式或订单列表分站展示。

支付流程有两个不同地址：

| 地址 | 访问者 | 行为 |
| --- | --- | --- |
| `return_url` | 用户浏览器 | 自动回到用户发起支付的前端，例如春秋用户回春秋、机长用户回机长。 |
| `notify_url` | 支付平台服务器 | 统一访问配置的“支付回调域名”；该地址反代到后端，浏览器不会访问或显示它。 |

因此，即使统一回调域名使用机长前端域名，春秋用户也不会看到或跳转到机长域名。支付平台的通知是服务器之间的 POST；真正决定用户页面的是 `return_url`。

## 后台配置

在任意一个管理员后台的“支付配置”中新增或编辑渠道。该配置是全局可见的。

“支付回调域名”必填，填写一个你控制的 HTTPS 公网域名，例如：

```text
https://pay.example.com
```

也可以使用三个前端中的任意一个域名，例如：

```text
https://mo.example.com
```

不要填写后端 API 域名。保存后后台显示的通知地址会是：

```text
https://支付回调域名/api/v1/guest/payment/notify/支付方式/支付UUID
```

没有配置回调域名时，系统会拒绝发起支付，而不会退回或泄露后端 `APP_URL`。

## 回调域名的 Nginx 反代

在支付回调域名所对应的站点配置中，只将支付通知路径反代到后端 API 域名；其他路径继续正常提供前端静态文件。

```nginx
location ^~ /api/v1/guest/payment/notify/ {
    proxy_pass https://<后端API域名>;
    proxy_set_header Host <后端API域名>;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
    proxy_connect_timeout 15s;
    proxy_read_timeout 30s;
}
```

`proxy_pass` 后面不加 URI 路径，Nginx 会保留 `/api/v1/guest/payment/notify/...`，从而命中 V2Board 的支付通知路由。

若回调域名使用 Cloudflare：

1. 确保该路径允许 `POST`，没有被 WAF、挑战页或访问规则拦截。
2. 对 `/api/v1/guest/payment/notify/*` 配置跳过缓存；Cloudflare 默认不会缓存 POST，但规则应避免将响应缓存或改写。
3. 仅接受支付网关已验证的通知。V2Board 仍会执行各支付方式自己的签名验证，不能只根据前端跳转给订单开通。

## 前端回跳的要求

浏览器调用 `/api/v1/user/order/checkout` 时应带正常的 `Origin` 请求头。EZNEXT 的浏览器请求天然会带该头；系统使用它生成当前前端的 `/#/order/<订单号>` 地址。

如果某个非浏览器客户端没有 `Origin`，请在后端环境配置对应的 `SITE_1_URL`、`SITE_2_URL`、`SITE_3_URL`，作为安全兜底；不要把这些值填成后端 API 域名。

## 验证

1. 在一个前端创建订单，确认支付请求的 `return_url` 是该前端域名。
2. 确认支付请求的 `notify_url` 是支付回调域名，而非后端 API 域名。
3. 让支付平台发起测试通知，确认反代命中 V2Board，订单状态变为已完成。
4. 分别从三个前端付款一次，确认后台支付方式列表一致、后台订单列表包含三站订单，而用户自己的订单仍只会按其账户查询。
