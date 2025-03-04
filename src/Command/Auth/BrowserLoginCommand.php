<?php
namespace Platformsh\Cli\Command\Auth;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Url;
use Platformsh\Cli\Util\PortUtil;
use Platformsh\Client\Session\SessionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class BrowserLoginCommand extends CommandBase
{
    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $applicationName = $this->config()->get('application.name');
        $executable = $this->config()->get('application.executable');

        $this->setName('auth:browser-login');
        if ($this->config()->get('application.login_method') === 'browser') {
            $this->setAliases(['login']);
        }

        $this->setDescription('Log in to ' . $service . ' via a browser')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Log in again, even if already logged in');
        Url::configureInput($this->getDefinition());

        $help = 'Use this command to log in to the ' . $applicationName . ' using a browser.'
            . "\n\nAlternatively, to log in with a username and password in the terminal, use:\n    <info>"
            . $executable . ' auth:password-login</info>';
        if ($aHelp = $this->getApiTokenHelp()) {
            $help .= "\n\n" . $aHelp;
        }
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->api()->hasApiToken()) {
            $this->stdErr->writeln('Cannot log in: an API token is set');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive login is not supported.');
            if ($aHelp = $this->getApiTokenHelp('comment')) {
                $this->stdErr->writeln("\n" . $aHelp);
            }
            return 1;
        }
        $connector = $this->api()->getClient(false)->getConnector();
        if (!$input->getOption('force') && $connector->isLoggedIn()) {
            // Get account information, simultaneously checking whether the API
            // login is still valid. If the request works, then do not log in
            // again (unless --force is used). If the request fails, proceed
            // with login.
            try {
                $account = $this->api()->getMyAccount(true);

                $this->stdErr->writeln(sprintf('You are already logged in as <info>%s</info> (%s).',
                    $account['username'],
                    $account['mail']
                ));

                if ($input->isInteractive()) {
                    /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                    $questionHelper = $this->getService('question_helper');
                    if (!$questionHelper->confirm('Log in anyway?', false)) {
                        return 1;
                    }
                } else {
                    // USE THE FORCE
                    $this->stdErr->writeln('Use the <comment>--force</comment> (<comment>-f</comment>) option to log in again.');

                    return 0;
                }
            } catch (BadResponseException $e) {
                if ($e->getResponse() && in_array($e->getResponse()->getStatusCode(), [400, 401], true)) {
                    $this->debug('Already logged in, but a test request failed. Continuing with login.');
                } else {
                    throw $e;
                }
            }
        }

        // Set up the local PHP web server, which will serve an OAuth2 redirect
        // and wait for the response.
        // Firstly, find an address. The port needs to be within a known range,
        // for validation by the remote server.
        try {
            $start = 5000;
            $end = 5010;
            $port = PortUtil::getPort($start, null, $end);
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'failed to find') !== false) {
                $this->stdErr->writeln(sprintf('Failed to find an available port between <error>%d</error> and <error>%d</error>.', $start, $end));
                $this->stdErr->writeln('Check if you have unnecessary services running on these ports.');
                $this->stdErr->writeln(sprintf('For more options, run: <info>%s help login</info>', $this->config()->get('application.executable')));

                return 1;
            }
            throw $e;
        }
        $localAddress = '127.0.0.1:' . $port;
        $localUrl = 'http://' . $localAddress;

        // Then create the document root for the local server. This needs to be
        // outside the CLI itself (since the CLI may be run as a Phar).
        $listenerDir = $this->config()->getWritableUserDir() . '/oauth-listener';
        $this->createDocumentRoot($listenerDir);

        // Create the file where an authorization code will be saved (by the
        // local server script).
        $codeFile = $listenerDir . '/.code';
        if (file_put_contents($codeFile, '', LOCK_EX) === false) {
            throw new \RuntimeException('Failed to create temporary file: ' . $codeFile);
        }
        chmod($codeFile, 0600);

        // Start the local server.
        $process = new Process([
            (new PhpExecutableFinder())->find() ?: PHP_BINARY,
            '-dvariables_order=egps',
            '-S',
            $localAddress,
            '-t',
            $listenerDir
        ]);
        $process->setEnv([
            'CLI_OAUTH_APP_NAME' => $this->config()->get('application.name'),
            'CLI_OAUTH_STATE' => $this->getRandomState(),
            'CLI_OAUTH_AUTH_URL' => $this->config()->get('api.oauth2_auth_url'),
            'CLI_OAUTH_CLIENT_ID' => $this->config()->get('api.oauth2_client_id'),
            'CLI_OAUTH_FILE' => $codeFile,
        ]);
        $process->setTimeout(null);
        $this->stdErr->writeln('Starting local web server with command: <info>' . $process->getCommandLine() . '</info>', OutputInterface::VERBOSITY_VERY_VERBOSE);
        $process->start();

        // Give the local server some time to start before checking its status
        // or opening the browser (0.5 seconds).
        usleep(500000);

        // Check the local server status.
        if (!$process->isRunning()) {
            $this->stdErr->writeln('Failed to start local web server.');
            $this->stdErr->writeln(trim($process->getErrorOutput()));

            return 1;
        }

        // Open the local server URL in a browser (or print the URL).
        /** @var \Platformsh\Cli\Service\Url $urlService */
        $urlService = $this->getService('url');
        if ($urlService->openUrl($localUrl, false)) {
            $this->stdErr->writeln(sprintf('Opened URL: <info>%s</info>', $localUrl));
            $this->stdErr->writeln('Please use the browser to log in.');
        } else {
            $this->stdErr->writeln('Please open the following URL in a browser and log in:');
            $this->stdErr->writeln('<info>' . $localUrl . '</info>');
        }

        // Show some help.
        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold>Help:</>');
        $this->stdErr->writeln('  Use Ctrl+C to quit this process.');
        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln(sprintf('  To log in within the terminal instead, quit and run: <info>%s auth:password-login</info>', $executable));
        $this->stdErr->writeln(sprintf('  For more info, quit and run: <info>%s help login</info>', $executable));
        $this->stdErr->writeln('');

        // Wait for the file to be filled with an OAuth2 authorization code.
        $code = null;
        while ($process->isRunning() && empty($code)) {
            usleep(300000);
            if (!file_exists($codeFile)) {
                $this->stdErr->writeln('File not found: <error>' . $codeFile . '</error>');
                $this->stdErr->writeln('');
                break;
            }
            $code = file_get_contents($codeFile);
            if ($code === false) {
                $this->stdErr->writeln('Failed to read file: <error>' . $codeFile . '</error>');
                $this->stdErr->writeln('');
                break;
            }
        }

        // Clean up.
        $process->stop();
        (new Filesystem())->remove([$listenerDir]);

        if (empty($code)) {
            $this->stdErr->writeln('Failed to get an authorization code. Please try again.');

            return 1;
        }

        // Using the authorization code, request an access token.
        $this->stdErr->writeln('Login information received. Verifying...');
        $token = $this->getAccessToken($code, $localUrl);

        // Finalize login: call logOut() on the old connector, clear the cache
        // and save the new credentials.
        $connector = $this->api()->getClient(false)->getConnector();
        $session = $connector->getSession();
        $connector->logOut();

        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();

        // Save the new tokens to the persistent session.
        $this->saveAccessToken($token, $session);

        // Reset the API client so that it will use the new tokens.
        $client = $this->api()->getClient(false, true);
        $this->stdErr->writeln('You are logged in.');

        // Show user account info.
        $info = $client->getAccountInfo();
        $this->stdErr->writeln(sprintf(
            "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
            $info['username'],
            $info['mail']
        ));

        return 0;
    }

    /**
     * @param array            $tokenData
     * @param SessionInterface $session
     */
    private function saveAccessToken(array $tokenData, SessionInterface $session)
    {
        $token = new AccessToken($tokenData['access_token'], $tokenData['token_type'], $tokenData);
        $session->setData([
            'accessToken' => $token->getToken(),
            'tokenType' => $token->getType(),
        ]);
        if ($token->getExpires()) {
            $session->set('expires', $token->getExpires()->getTimestamp());
        }
        if ($token->getRefreshToken()) {
            $session->set('refreshToken', $token->getRefreshToken()->getToken());
        }
        $session->save();
    }

    /**
     * @param string $dir
     */
    private function createDocumentRoot($dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Failed to create temporary directory: ' . $dir);
        }
        if (!file_put_contents($dir . '/index.php', file_get_contents(CLI_ROOT . '/resources/oauth-listener/index.php'))) {
            throw new \RuntimeException('Failed to write temporary file: ' . $dir . '/index.php');
        }
    }

    /**
     * Exchange the authorization code for an access token.
     *
     * @param string $authCode
     * @param string $redirectUri
     *
     * @return array
     */
    private function getAccessToken($authCode, $redirectUri)
    {
        return (new Client())->post(
            $this->config()->get('api.oauth2_token_url'),
            [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $authCode,
                    'client_id' => $this->config()->get('api.oauth2_client_id'),
                    'redirect_uri' => $redirectUri,
                ],
                'auth' => false,
                'verify' => !$this->config()->get('api.skip_ssl'),
            ]
        )->json();
    }

    /**
     * Get a random state to use with the OAuth2 code request.
     *
     * @return string
     */
    private function getRandomState()
    {
        // This uses paragonie/random_compat as a polyfill for PHP < 7.0.
        return bin2hex(random_bytes(128));
    }
}
