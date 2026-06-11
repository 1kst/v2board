<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱验证码</title>
    <style>
        /* Basic styles */
        body { margin: 0; padding: 0; background-color: #e9ecef; /* Slightly grey outer background */ }
        table { border-collapse: collapse; }
        td { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji', 'Microsoft YaHei', '微软雅黑'; vertical-align: top; } /* Modern font stack */
        a { color: #4A90E2; /* Link color matching header */ text-decoration: none; }
        p { margin: 0 0 1em 0; font-size: 15px; color: #555555; line-height: 1.6; }
        h1 { margin: 0 0 0.8em 0; font-size: 22px; line-height: 1.4; color: #333333; font-weight: 600; } /* Slightly bolder heading */

        /* Highlight for the code */
        .code-highlight {
            font-size: 19px; /* Slightly larger */
            color: #E94E1B; /* Vibrant orange */
            font-weight: bold;
            letter-spacing: 1px;
            background-color: #fef0e7; /* Very light orange background */
            padding: 2px 6px; /* Padding around code */
            border-radius: 4px; /* Subtle radius for code itself */
            display: inline-block; /* Needed for padding/bg */
        }
        /* Subtle text style */
        .subtle-text {
            font-size: 13px; color: #888888; line-height: 1.6;
        }

        /* --- Rounded Corners --- */
        /* Applied via inline styles below for maximum compatibility */
        /* Note: border-radius support varies across email clients, especially Outlook */

    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #e9ecef;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #e9ecef;">
        <tr>
            <td align="center" style="padding: 30px 15px;"> <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; background-color: #ffffff; margin: 0 auto; border-radius: 12px; /* Main rounding */ overflow: hidden; /* Helps clip content */">
                    <thead>
                        <tr>
                            <td style="background-color: #4A90E2; /* Modern Blue */ color: #ffffff; padding: 25px 30px; font-size: 20px; font-weight: 600; line-height: 1.4; border-top-left-radius: 12px; border-top-right-radius: 12px;">
                                {{$name}}
                            </td>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 35px 30px 25px 30px;">
                                <h1 style="font-size: 22px; line-height: 1.4; color: #333333; margin-bottom: 25px;">
                                    请验证您的邮箱地址
                                </h1>
                                <p style="color: #555555;">
                                    尊敬的用户您好！
                                </p>
                                <p style="color: #555555;">
                                    感谢您使用 {{$name}}。您的邮箱验证码是：
                                    <br>
                                    <span class="code-highlight">{{$code}}</span>
                                </p>
                                <p style="color: #555555;">
                                    该验证码将在 <strong>5 分钟</strong> 内失效。请尽快完成验证。
                                </p>
                                <p class="subtle-text">
                                    如果该验证码并非您本人申请，或您未进行相关操作，请忽略此邮件，无需担心。
                                </p>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td style="padding: 20px 30px; font-size: 12px; color: #999999; line-height: 1.5; background-color: #f8f9fa; /* Very light grey footer */ text-align: center; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
                                <a href="{{$url}}" style="color: #777777; /* Slightly darker link */ text-decoration: none;">
                                    访问 {{$name}} 官网
                                </a>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <tr>
                    <td align="center" style="padding: 20px 15px; font-size: 11px; color: #aaaaaa; line-height: 1.5;">
                        此邮件由系统自动发送，请勿直接回复。<br>
                        © {{date('Y')}} {{$name}}. 保留所有权利。
                    </td>
                </tr>
            </td>
        </tr>
    </table>
</body>
</html>
