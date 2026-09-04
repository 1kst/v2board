# Three-site deployment

The merged database assigns `chunqiux=1`, `jichang=2`, and `yzjiasu=3`.
Set a distinct routing key and mail profile for every site in the server's
environment. Do not commit this file with real credentials.

```dotenv
SITE_1_KEY=replace-with-a-random-value
SITE_1_NAME=春秋加速
SITE_1_URL=https://panel.example-one.com
SITE_1_EMAIL_HOST=smtp.example-one.com
SITE_1_EMAIL_PORT=465
SITE_1_EMAIL_ENCRYPTION=ssl
SITE_1_EMAIL_USERNAME=mailer@example-one.com
SITE_1_EMAIL_PASSWORD=replace-me
SITE_1_EMAIL_FROM_ADDRESS=mailer@example-one.com

SITE_2_KEY=replace-with-a-different-random-value
SITE_2_NAME=机场加速
SITE_2_URL=https://panel.example-two.com
SITE_2_EMAIL_HOST=smtp.example-two.com
SITE_2_EMAIL_PORT=465
SITE_2_EMAIL_ENCRYPTION=ssl
SITE_2_EMAIL_USERNAME=mailer@example-two.com
SITE_2_EMAIL_PASSWORD=replace-me
SITE_2_EMAIL_FROM_ADDRESS=mailer@example-two.com

SITE_3_KEY=replace-with-a-third-random-value
SITE_3_NAME=YZ加速
SITE_3_URL=https://panel.example-three.com
SITE_3_EMAIL_HOST=smtp.example-three.com
SITE_3_EMAIL_PORT=465
SITE_3_EMAIL_ENCRYPTION=ssl
SITE_3_EMAIL_USERNAME=mailer@example-three.com
SITE_3_EMAIL_PASSWORD=replace-me
SITE_3_EMAIL_FROM_ADDRESS=mailer@example-three.com
```

`SITE_n_EMAIL_TEMPLATE` defaults to `site1`, `site2`, or `site3`. Each has an
independent view directory under `resources/views/mail/`; initially they reuse
the existing DIY layout and receive their own name, URL, sender and SMTP
profile. Replace a site's wrapper with its own Blade markup when its visual
mail design needs to diverge.

All API clients for site 2 and 3 must send `X-Site-ID` and the matching
`X-Site-Key`. Requests without a site header remain compatible with site 1.
Subscription requests remain domain-independent: their user token determines
the site after lookup.

After changing environment values on the server, run `php artisan config:clear`
and restart Horizon/queue workers. A queue worker must restart so it does not
retain a previous site's configuration in memory.
