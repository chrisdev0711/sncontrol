<?php

namespace App\Http\Controllers\Central;

use App\Actions\CreateTenantAction;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class RegisterTenantController extends Controller
{
    public function show()
    {
        return view('central.tenants.register');
    } 

    public function submit(Request $request)
    {
        $ploi = new \Ploi\Ploi();
        $token = env("PLOI_TOKEN");
        $ploi->setApiToken($token);
        
        $data = $this->validate($request, [
            'domain' => 'required|string|unique:domains',
            'company' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:tenants',
            'password' => 'required|string|confirmed|max:255',
        ]);

        $userName = $data['name'];
        $email = $data['email'];
        $password = $data['password'];

        // Get server id and name
        $servers = $ploi->servers()->get()->getData();
        foreach ($servers as $server) {
            if ($server->name === 'sndev3-up-lon1') {
                $serverId = $server->id;
                $serverName = $server->name;
            }
        };
        sleep(10);
        // Create Site
        $ploi->servers($serverId)->sites()->create(
            // $domain = $data['domain'].'.stocknow.dev',
            $domain = $data['domain'].'.stocknow.dev',
            $webDirectory = '/public',
            $projectDirectory = '/',
            $systemUser = 'ploi',
            $systemUserPassword = null,
            $webserverTemplate = null,
            $projectType = 'laravel',
        );
        sleep(10);
        // Get site id and domain
        $sites = $ploi->servers($serverId)->sites()->get()->getData();
        foreach ($sites as $site) {
            if ($site->domain == $data['domain'].'.stocknow.dev') {
            // if ($site->domain == "domain1.stocknow.dev") {
                $siteId = $site->id;
                $siteDomain = $site->domain;
            }
        };
        sleep(10);
        // Create Database
        $ploi->servers($serverId)->databases()->create(
            $databaseName = $siteId . 'stocknow',
            $databaseUser = $siteId . 'root',
            $databaseUserPassword = $siteId . 'password',
        );
        sleep(10);
        // Get db username & password;
        $dbUser = $siteId.'root';
        $dbPwd = $siteId.'password';

        // Create Queues:
        $ploi->servers($serverId)->sites($siteId)->queues()->create(
            $connection = 'database',
            $queue = 'default',
            $maximumSeconds = 60,
            $sleep = 30,
            $processes = 1,
            $maximumTries = 1
        );
        sleep(10);

        // Create Certificate
        $ploi->servers($serverId)->sites($siteId)->certificates()->create(
            $certificate = $siteDomain,
            $type = 'letsencrypt',
        );
        sleep(10);

        // Repository install
        $ploi->servers($serverId)->sites($siteId)->repository()->install(
            $provider = 'github',
            $branch = 'main',
            $name = 'snappyio/stocknow',
        );

        // sleep(10);
        
        // Development
        $deployment = "cd /home/ploi/".$siteDomain."\ngit pull origin main\ncomposer install --no-interaction --prefer-dist --optimize-autoloader\nphp artisan key:generate\nphp artisan config:clear\nphp artisan cache:clear\nphp artisan route:cache\nphp artisan view:clear\nphp artisan migrate --force\nphp artisan db:seed --force\necho \"\" | sudo -S service php7.4-fpm reload\necho \"🚀 Application deployed!\"";
        dd($deployment);
        $ploi->servers($serverId)->sites($serverId)->deployment()->deployScript();
        $ploi->servers($serverId)->sites($siteId)->deployment()->updateDeployScript(
            $deployment,
        );
        $ploi->servers($serverId)->sites($siteId)->deployment()->deploy();

        sleep(10);

        // Update the Env
        $env = "APP_NAME=StockNow\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=http://{$siteDomain}\nLOG_CHANNEL=stack\nLOG_LEVEL=debug\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE={$siteId}stocknow\nDB_USERNAME=\"{$dbUser}\"\nDB_PASSWORD=\"{$dbPwd}\"\nUSER_NAME=\"{$userName}\"\nUSER_EMAIL={$email}\nUSER_PASSWORD={$password}\nBROADCAST_DRIVER=log\nCACHE_DRIVER=file\nFILESYSTEM_DRIVER=local\nQUEUE_CONNECTION=sync\nSESSION_DRIVER=database\nSESSION_LIFETIME=120\nMEMCACHED_HOST=127.0.0.1\nREDIS_HOST=127.0.0.1\nREDIS_PASSWORD=null\nREDIS_PORT=6379\nMAIL_MAILER=smtp\nMAIL_HOST=mailhog\nMAIL_PORT=1025\nMAIL_USERNAME=null\nMAIL_PASSWORD=null\nMAIL_ENCRYPTION=null\nMAIL_FROM_ADDRESS=null\nMAIL_FROM_NAME=Laravel\nAWS_ACCESS_KEY_ID=\nAWS_SECRET_ACCESS_KEY=\nAWS_DEFAULT_REGION=us-east-1\nAWS_BUCKET=\nAWS_USE_PATH_STYLE_ENDPOINT=false\nPUSHER_APP_ID=\nPUSHER_APP_KEY=\nPUSHER_APP_SECRET=\nPUSHER_APP_CLUSTER=mt1\nMIX_PUSHER_APP_KEY=PUSHER_APP_KEY\nMIX_PUSHER_APP_CLUSTER=PUSHER_APP_CLUSTER\n";
        $ploi->servers($serverId)->sites($siteId)->environment()->update(
            $env
        );

     
        // Create Script
        $script = "php artisan key:generate\ncomposer install\nphp artisan migreate\nphp artisan db:seed\n";
        // $script = "cp .env.example .env\n";
        $ploi->scripts()->create(
            $label = "Run script",
            $user = 'ploi',
            $script,
        );

        // Get script id;
        $scripts = $ploi->scripts()->get()->getData();
        foreach (array_reverse($scripts) as $script) {
            $scriptId = $script->id;
        }

        // Run script
        $ploi->scripts($scriptId)->run(
            $id = $scriptId,
            $serverIds = [$serverId],
        );

        sleep(10);

    
        // Create new tenant and redirect to new subdomain
        $data['password'] = bcrypt($data['password']);
        $tenant = (new CreateTenantAction)($data, $data['domain']);

        return redirect()->away("https://{$siteDomain}");
    }
}
