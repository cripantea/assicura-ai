
@setup
$server = 'srv961648.hstgr.cloud';

$baseDir = '/var/www/html/ai';
$user = 'cristi';

$php = 'php';
$userAndServer = $user . '@'. $server;
$repository = "/cripantea/assicura-ai.git";
$npm = 'npm';

$branch = $branch ?? 'main';

# naming convention
$releasesDir = "{$baseDir}/releases";
$persistentDir = "{$baseDir}/persistent";
$currentDir = "{$baseDir}/current";
$newReleaseName = date('Ymd-His');
$newReleaseDir = "{$releasesDir}/{$newReleaseName}";


function logMessage($message) {
return "echo '\033[32m" .$message. "\033[0m';\n";
}
@endsetup

@servers(['local' => '127.0.0.1', 'remote' => $userAndServer])

@story('deploy')
cloneRepository
runComposer
runNpm
generateAssets
updateSymlinks
optimizeInstallation
migrateDatabase
blessNewRelease
cleanOldReleases
finishDeploy
@endstory

@task('testaa', ['on' => 'remote'])
{{-- whoami --}}
whoami
pwd

{{ $npm }} -v
@endtask

@task('cloneRepository', ['on' => 'remote'])
{{ logMessage('🌀  Cloning repository…') }}
[ -d {{ $releasesDir }} ] || mkdir {{ $releasesDir }};
[ -d {{ $persistentDir }} ] || mkdir {{ $persistentDir }};
[ -d {{ $persistentDir }}/uploads ] || mkdir {{ $persistentDir }}/uploads;
[ -d {{ $persistentDir }}/storage ] || mkdir {{ $persistentDir }}/storage;
[ -d {{ $persistentDir }}/storage/framework ] || mkdir {{ $persistentDir }}/storage/framework;
[ -d {{ $persistentDir }}/storage/framework/cache ] || mkdir {{ $persistentDir }}/storage/framework/cache;
[ -d {{ $persistentDir }}/storage/framework/sessions ] || mkdir {{ $persistentDir }}/storage/framework/sessions;
[ -d {{ $persistentDir }}/storage/framework/views ] || mkdir {{ $persistentDir }}/storage/framework/views;

cd {{ $releasesDir }};

# Create the release dir
mkdir {{ $newReleaseDir }};


# Clone the repo
git clone --depth 1 --branch {{ $branch }} git@github.com:{{ $repository }} {{ $newReleaseName }}

# Configure sparse checkout
cd {{ $newReleaseDir }}
git config core.sparsecheckout true
echo "*" > .git/info/sparse-checkout
echo "!storage" >> .git/info/sparse-checkout
echo "!public/build" >> .git/info/sparse-checkout
git read-tree -mu HEAD

# Mark release
cd {{ $newReleaseDir }}
echo "{{ $newReleaseName }}" > public/release-name.txt
@endtask

@task('runComposer', ['on' => 'remote'])
cd {{ $newReleaseDir }};
{{ logMessage('🚚  Running Composer…') }}
{{-- TODO no-dev --}}
{{-- {{ $php }} {{$baseDir}}/composer.phar install --prefer-dist --no-scripts --no-dev -q -o; --}}
{{-- {{ $php }} {{$baseDir}}/composer.phar install --prefer-dist --no-scripts --no-dev -q -o; --}}
composer update;
composer install --prefer-dist --no-scripts -q -o --no-dev;
@endtask

@task('runNpm', ['on' => 'remote'])
{{ logMessage('📦  Running Npm…') }}
cd {{ $newReleaseDir }};
npm  install
@endtask

@task('generateAssets', ['on' => 'remote'])
{{ logMessage('🌅  Generating assets…') }}
cd {{ $newReleaseDir }};
npm run build
@endtask

@task('updateSymlinks', ['on' => 'remote'])
{{ logMessage('🔗  Updating symlinks to persistent data…') }}
# Remove the storage directory and replace with persistent data
rm -rf {{ $newReleaseDir }}/storage;
cd {{ $newReleaseDir }};
ln -nfs {{ $baseDir }}/persistent/storage storage;

# Import the environment config
cd {{ $newReleaseDir }};
ln -nfs {{ $baseDir }}/.env .env;

# Symlink the persistent fonts to the public directory
#cd {{ $baseDir }}/persistent/fonts
#git pull origin master
#ln -nfs {{ $baseDir }}/persistent/fonts {{ $newReleaseDir }}/public/fonts;
@endtask

@task('optimizeInstallation', ['on' => 'remote'])
{{ logMessage('✨  Optimizing installation…') }}
cd {{ $newReleaseDir }};
{{ $php }} artisan clear-compiled;
{{ logMessage('✨  end Optimizing installation…') }}
@endtask

@task('backupDatabase', ['on' => 'remote'])
{{-- {{ logMessage('📀  Backing up database…') }} --}}
{{-- cd {{ $newReleaseDir }} --}}
{{--  $php }} artisan backup:run --}}
@endtask

@task('migrateDatabase', ['on' => 'remote'])
{{-- {{ logMessage('🙈  Migrating database…') }} --}}
cd {{ $newReleaseDir }};
php artisan migrate --force;
cd {{ $newReleaseDir }}
@endtask

@task('blessNewRelease', ['on' => 'remote'])
{{ logMessage('🙏  Blessing new release…') }}
ln -nfs {{ $newReleaseDir }} {{ $currentDir }};
cd {{ $newReleaseDir }}
{{-- {{ $php }} artisan horizon:terminate --}}
{{ $php }} artisan config:clear
{{ $php }} artisan view:clear
{{-- {{ $php }} artisan cache:forget spatie.permission.cache --}}
{{-- {{ $php }} artisan cache:clear --}}

{{ $php }} artisan schedule:clear-cache
{{--
{{ $php }} artisan schedule-monitor:sync
--}}

{{ $php }} artisan queue:restart
{{ $php }} artisan config:cache
{{ $php }} artisan storage:link
{{-- {{ $php }} artisan icons:cache --}}
{{-- {{ $php }} artisan responsecache:clear --}}

{{--
echo "" | sudo -S /usr/sbin/service php83-fpm reload
--}}

{{-- sudo supervisorctl restart all --}}
@endtask

@task('cleanOldReleases', ['on' => 'remote'])
{{ logMessage('🚾  Cleaning up old releases…') }}
# Delete all but the 5 most recent.
cd {{ $releasesDir }}
ls -dt {{ $releasesDir }}/* | tail -n +6 | xargs -d "\n" chown -R {{ $user }} .;
ls -dt {{ $releasesDir }}/* | tail -n +6 | xargs -d "\n" rm -rf;
@endtask

@task('finishDeploy', ['on' => 'local'])
{{ logMessage('🚀  Application deployed!') }}
@endtask

@task('deployOnlyCode', ['on' => 'remote'])
{{ logMessage('💻  Deploying code changes…') }}
cd {{ $currentDir }}
git pull origin {{ $branch }}
{{ $php }} artisan config:clear
{{ $php }} artisan view:clear
{{-- {{ $php }} artisan cache:clear --}}
{{ $php }} artisan schedule:clear-cache
{{ $php }} artisan schedule-monitor:sync

{{ $php }} artisan queue:restart
{{ $php }} artisan config:cache
{{ $php }} artisan icons:cache


{{-- {{ $php }} artisan responsecache:clear --}}
{{-- sudo supervisorctl restart all --}}
echo "" | sudo -S /usr/sbin/service plesk-php81-fpm reload
@endtask
