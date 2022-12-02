<?php

declare(strict_types=1);

namespace Lullabot\Drainpipe;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Symfony\Component\Yaml\Yaml;

class ScaffoldInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * Composer instance configuration.
     *
     * @var Config
     */
    protected $config;

    /**
     * Composer extra field configuration.
     *
     * @var array
     */
    protected $extra;

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->extra = $composer->getPackage()->getExtra();
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallCmd',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdateCmd',
        ];
    }

    /**
     * Handle post install command events.
     *
     * @param Event $event the event to handle
     */
    public function onPostInstallCmd(Event $event)
    {
        $this->installTaskfile();
        $this->installGitignore();
        $this->installDdevCommand();
        $this->installCICommands();
        $this->installEnvSupport();
    }

    /**
     * Handle post update command events.
     *
     * @param event $event The event to handle
     */
    public function onPostUpdateCmd(Event $event)
    {
        $this->installTaskfile();
        $this->installGitignore();
        $this->installDdevCommand();
        $this->installCICommands();
        $this->installEnvSupport();
    }

    /**
     * Copies Taskfile.yml from the scaffold directory if it doesn't yet exist.
     */
    private function installTaskfile(): void
    {
        $vendor = $this->config->get('vendor-dir');
        $taskfilePath = $vendor.'/lullabot/drainpipe/scaffold/Taskfile.yml';

        if (!file_exists('./Taskfile.yml')) {
            $this->io->write('<info>Creating initial Taskfile.yml...</info>');
            $fs = new Filesystem();
            $fs->copy(
                $taskfilePath,
                './Taskfile.yml'
            );
        } else {
            $scaffoldTaskFile = Yaml::parseFile($taskfilePath);
            $projectTaskfile = Yaml::parseFile('./Taskfile.yml');
            foreach ($scaffoldTaskFile['includes'] as $key => $value) {
                if (empty($projectTaskfile['includes'][$key]) || $projectTaskfile['includes'][$key] !== $value) {
                    $this->io->warning(
                        'Taskfile.yml has either been customized or requires review.'
                    );
                    $this->io->warning(
                        sprintf(
                            'Compare Taskfile.yml includes in the root of your repository with %s and update as needed.',
                            $taskfilePath
                        )
                    );
                    break;
                }
            }
        }
    }

    /**
     * Copies gitignore from the scaffold directory if it doesn't yet exist.
     */
    private function installGitignore(): void
    {
        $vendor = $this->config->get('vendor-dir');
        $gitignorePath = $vendor.'/lullabot/drainpipe/scaffold/gitignore';
        if (!file_exists('./.gitignore')) {
            $this->io->write('<info>Creating initial .gitignore...</info>');
            $fs = new Filesystem();
            $fs->copy("$gitignorePath/common.gitignore", './.gitignore');
            if (file_exists('./.ddev/config.yaml')) {
                $fp = fopen('./.gitignore', 'a');
                fwrite($fp, file_get_contents("$gitignorePath/ddev.gitignore"));
                fclose($fp);
            }
        } else {
            $contents = file_get_contents('./.gitignore');
            if (strpos($contents, '.task') === false) {
                $this->io->warning(
                    sprintf(
                    '.gitignore does not contain drainpipe ignores. Compare .gitignore in the root of your repository with %s and update as needed.',
                        $gitignorePath
                    )
                );
            }
        }
    }

    /**
     * Install .env support.
     */
    private function installEnvSupport(): void
    {
        $fs = new Filesystem();
        $vendor = $this->config->get('vendor-dir');
        // Copy this over as the other files in composer drupal-scaffold
        // are added to the gitignore, and this should be checked in.
        if (!is_file('./.env.defaults')) {
            $fs->copy($vendor . '/lullabot/drainpipe/scaffold/env/env.defaults', './.env.defaults');
        }
        // There has to be a better way of doing this?
        $vendorRelative = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $vendor);
        $composerJson = file_get_contents('composer.json');
        $composerFullConfig = json_decode($composerJson, true);
        if (empty($composerFullConfig['autoload-dev']['files']) || !in_array("$vendorRelative/lullabot/drainpipe/scaffold/env/dotenv.php", $composerFullConfig['autoload-dev']['files'])) {
            $this->io->warning("🪠 [Drainpipe] $vendorRelative/lullabot/drainpipe/scaffold/env/dotenv.php' missing from autoload-dev files");
        }
    }

    /**
     * Install DDEV Commands.
     */
    private function installDdevCommand(): void
    {
        if (file_exists('./.ddev/config.yaml')) {
            $vendor = $this->config->get('vendor-dir');
            $ddevCommandPath = $vendor . '/lullabot/drainpipe/scaffold/ddev/task-command.sh';
            $fs = new Filesystem();
            $fs->ensureDirectoryExists('./.ddev/commands/web');
            $fs->copy($ddevCommandPath, './.ddev/commands/web/task');
        }
    }

    /**
     * Install CI Commands.
     */
    private function installCICommands(): void
    {
        $scaffoldPath = $this->config->get('vendor-dir') . '/lullabot/drainpipe/scaffold';
        $fs = new Filesystem();
        // GitLab
        $fs->removeDirectory('./.drainpipe/gitlab');
        if (isset($this->extra['drainpipe']['gitlab']) && is_array($this->extra['drainpipe']['gitlab'])) {
            $fs->ensureDirectoryExists('./.drainpipe/gitlab');
            $fs->copy("$scaffoldPath/gitlab/Common.gitlab-ci.yml", ".drainpipe/gitlab/Common.gitlab-ci.yml");
            $this->io->write("🪠 [Drainpipe] .drainpipe/gitlab/Common.gitlab-ci.yml installed");
            foreach ($this->extra['drainpipe']['gitlab'] as $gitlab) {
                $file = "gitlab/$gitlab.gitlab-ci.yml";
                if (file_exists("$scaffoldPath/$file")) {
                    $fs->copy("$scaffoldPath/$file", ".drainpipe/$file");
                    $this->io->write("🪠 [Drainpipe] .drainpipe/$file installed");
                }
                else {
                    $this->io->warning("🪠 [Drainpipe] $scaffoldPath/$file does not exist");
                }

                if ($gitlab === 'Pantheon') {
                    // @TODO this isn't really specific to GitLab
                    // .drainpipeignore
                    if (!file_exists('.drainpipeignore')) {
                        $fs->copy("$scaffoldPath/pantheon/.drainpipeignore", '.drainpipeignore');
                    }
                    else {
                        $contents = file_get_contents('./.drainpipeignore');
                        if (strpos($contents, '/web/sites/default/files') === false) {
                            $this->io->warning(
                                sprintf(
                                    '.gitignore does not contain drainpipe ignores. Compare .drainpipeignore in the root of your repository with %s and update as needed.',
                                    "$scaffoldPath/pantheon/.drainpipeignore"
                                )
                            );
                        }
                    }
                    // pantheon.yml
                    if (!file_exists('./pantheon.yml')) {
                        $fs->copy("$scaffoldPath/pantheon/pantheon.yml", './pantheon.yml');
                    }
                    // settings.pantheon.php
                    if (!file_exists('./web/sites/default/settings.pantheon.php')) {
                        $fs->copy("$scaffoldPath/pantheon/settings.pantheon.php", './web/sites/default/settings.pantheon.php');
                    }
                }
            }
            if (!file_exists('./.gitlab-ci.yml')) {
                $fs->copy("$scaffoldPath/gitlab/gitlab-ci.example.yml", './.gitlab-ci.yml');
            }
        }
        // GitHub
        $fs->removeDirectory('./.github/actions/drainpipe');
        if (isset($this->extra['drainpipe']['github']) && is_array($this->extra['drainpipe']['github'])) {
            $fs->ensureDirectoryExists('./.github/actions');
            $fs->copy("$scaffoldPath/github/actions/common", './.github/actions/drainpipe');
            foreach ($this->extra['drainpipe']['github'] as $github) {
                if ($github === 'PantheonReviewApps') {
                    $fs->ensureDirectoryExists('./.github/actions/drainpipe/pantheon');
                    $fs->ensureDirectoryExists('./.github/workflows');
                    $fs->copy("$scaffoldPath/github/actions/pantheon", './.github/actions/drainpipe/pantheon');
                    if (file_exists('./.ddev/config.yaml')) {
                        $fs->copy("$scaffoldPath/github/workflows/PantheonReviewAppsDDEV.yml", './.github/workflows/PantheonReviewApps.yml');
                    }
                    else {
                        $fs->copy("$scaffoldPath/github/workflows/PantheonReviewApps.yml", './.github/workflows/PantheonReviewApps.yml');
                    }
                }
                else if ($github === 'ComposerLockDiff') {
                    $fs->ensureDirectoryExists('./.github/workflows');
                    $fs->copy("$scaffoldPath/github/workflows/ComposerLockDiff.yml", './.github/workflows/ComposerLockDiff.yml');
                }
            }
        }
    }
}
