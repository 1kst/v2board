<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站通知 - {{$name}}</title>
    <style>
        /* Basic styles from the target template */
        body { margin: 0; padding: 0; background-color: #e9ecef; }
        table { border-collapse: collapse; }
        td { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji', 'Microsoft YaHei', '微软雅黑'; vertical-align: top; }
        a { color: #4A90E2; text-decoration: none; }
        p { margin: 0 0 1em 0; font-size: 15px; color: #555555; line-height: 1.6; }
        h1 { margin: 0 0 0.8em 0; font-size: 22px; line-height: 1.4; color: #333333; font-weight: 600; }

        /* Subtle text style from the target template (optional, if needed for content) */
        .subtle-text {
            font-size: 13px; color: #888888; line-height: 1.6;
        }

        /* --- Rounded Corners --- Applied via inline styles */

    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #e9ecef;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #e9ecef;">
        <tr>
            <td align="center" style="padding: 30px 15px;">
                <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; background-color: #ffffff; margin: 0 auto; border-radius: 12px; overflow: hidden;">
                    <thead>
                        <tr>
                            <td style="background-color: #4A90E2; color: #ffffff; padding: 25px 30px; font-size: 20px; font-weight: 600; line-height: 1.4; border-top-left-radius: 12px; border-top-right-radius: 12px;">
                                {{$name}} </td>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 35px 30px 25px 30px;">
                                <h1 style="font-size: 22px; line-height: 1.4; color: #333333; margin-bottom: 25px;">
                                    网站通知
                                </h1>
                                <p style="color: #555555; font-size: 15px; line-height: 1.6; margin-bottom: 1em;">
                                    尊敬的用户您好！
                                </p>
                                <div style="color: #555555; font-size: 15px; line-height: 1.6; margin-bottom: 1em;">
                                    {!! nl2br($content) !!} </div>
                                </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td style="padding: 20px 30px; font-size: 12px; color: #999999; line-height: 1.5; background-color: #f8f9fa; text-align: center; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
                                <a href="{{$url}}" style="color: #777777; text-decoration: none;">
                                    返回 {{$name}}
                                </a>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <table border="0" cellpadding="0" cellspacing="0" width="600" style="width: 100%; max-width: 600px; margin: 0 auto;">
                 <tr>
                    <td align="center" style="padding: 20px 15px; font-size: 11px; color: #aaaaaa; line-height: 1.5; text-align: center;">
                            此邮件由系统自动发送，请勿直接回复。<br>
                            © {{ date('Y') }} {{$name}}. 保留所有权利。
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
