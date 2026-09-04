<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Models\MailLog;
use App\Services\SiteConfigService;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $params = $this->params;
        $siteId = (int)($params['site_id'] ?? 1);
        $site = app(SiteConfigService::class)->get($siteId);
        if ($site['email_host']) {
            Config::set('mail.host', $site['email_host']);
            Config::set('mail.port', $site['email_port']);
            Config::set('mail.encryption', $site['email_encryption']);
            Config::set('mail.username', $site['email_username']);
            Config::set('mail.password', $site['email_password']);
            Config::set('mail.from.address', $site['email_from_address']);
            Config::set('mail.from.name', $site['name']);
            // Queue workers are long-lived. Drop a previous site's SMTP mailer
            // so this job cannot reuse its authenticated connection.
            $manager = app('mail.manager');
            if (method_exists($manager, 'forgetMailers')) $manager->forgetMailers();
            elseif (method_exists($manager, 'purge')) $manager->purge();
        }
        $email = $params['email'];
        $subject = $params['subject'];
        $template = 'mail.' . $site['email_template'] . '.' . $params['template_name'];
        $params['template_name'] = view()->exists($template)
            ? $template
            : 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];
        try {
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => isset($error) ? $error : NULL
        ];

        MailLog::create($log);
        $log['config'] = config('mail');
        return $log;
    }
}
