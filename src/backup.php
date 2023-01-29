<?php

namespace MYO;

use Exception;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class cPanelAutoBackup
{
    private $cpanelURL;
    private $username;
    private $password;
    private $uploadToGDrive = false;
    private $deleteLocalFileAfterUpload = false;
    private $gClientCredentialsFilePath;
    private $gClientRefreshToken;
    private $path;

    private $loginURL;
    private $securityToken;
    private $sessionCookie;

    /**
     * @param string $cpanelURL
     * @param string $username
     * @param string $password
     */
    public function __construct(string $cpanelURL, string $username, string $password)
    {
        $this->cpanelURL = $cpanelURL;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * @return void
     */
    private function check(): void
    {
        if ($this->path == null) {
            throw new Exception("Path is not set! You can set it with setBackupPath() method.");
        }
        if ($this->uploadToGDrive && $this->gClientCredentialsFilePath == null) {
            throw new Exception("gClientCredentialsFilePath is not set! You can set it with setGClientCredentialsFile() method.");
        }
        if ($this->uploadToGDrive && $this->gClientRefreshToken == null) {
            throw new Exception("gClientRefreshToken is not set! You can set it with setGClientRefreshToken() method.");
        }
    }

    /**
     * @param string $path
     * 
     * @return void
     */
    public function setBackupPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * @param string $fileName
     * 
     * @return string
     */
    private function getBackupPath(string $fileName): string
    {
        return $this->path . "/" . $fileName;
    }

    /**
     * @param bool $upload
     * 
     * @return void
     */
    public function setUploadToGDrive(bool $uploadToGDrive = true, bool $deleteLocalFile = true): void
    {
        $this->uploadToGDrive = $uploadToGDrive;
        $this->deleteLocalFileAfterUpload = $deleteLocalFile;
    }

    /**
     * @param string $refreshToken
     * 
     * @return void
     */
    public function setGClientRefreshToken(string $refreshToken): void
    {
        $this->gClientRefreshToken = $refreshToken;
    }

    /**
     * @param string $credentialsFilePath
     * 
     * @return void
     */
    public function setGClientCredentialsFile(string $credentialsFilePath): void
    {
        $this->gClientCredentialsFilePath = $credentialsFilePath;
    }

    /**
     * @param string|null $customURL
     * 
     * @return void
     */
    public function setLoginURL(string $customURL = null): void
    {
        $this->loginURL = $customURL ?? $this->cpanelURL . "/login/?login_only=1";
    }

    /**
     * @param string $dbname
     * 
     * @return string
     */
    private function setBackupURL(string $dbname): string
    {
        return $this->cpanelURL . $this->securityToken . "/getsqlbackup/" . $dbname . ".sql.gz";
    }

    /**
     * @param string $dbname
     * 
     * @return void
     */
    public function backup(string $dbname): void
    {
        $this->check();
        $this->login();
        $fileName = $this->saveBackupFile($dbname);
        if ($this->uploadToGDrive) {
            $this->uploadBackupFileToGDrive($fileName);
            if ($this->deleteLocalFileAfterUpload) {
                $this->deleteBackupFile($fileName);
            }
        }
    }

    /**
     * @param string $fileName
     * 
     * @return void
     */
    private function deleteBackupFile(string $fileName): void
    {
        $filePath = $this->getBackupPath($fileName);
        if (!file_exists($filePath)) {
            throw new Exception("Backup file not found!");
        }
        unlink($filePath);
    }

    /**
     * @param string $dbname
     * 
     * @return string
     */
    private function saveBackupFile(string $dbname): string
    {
        $backupURL = $this->setBackupURL($dbname);
        $fileName = date("dmY_His", time()) . "_" . $dbname . ".sql.gz";

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Cookie: cpsession=" . $this->sessionCookie
        ]);
        curl_setopt($curl, CURLOPT_URL, $backupURL);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $rawBackupFile = curl_exec($curl);
        $responseHTTPCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseHTTPCode == 200) {
            $filePath = $this->getBackupPath($fileName);
            file_put_contents($filePath, $rawBackupFile);
            return $fileName;
        }
        throw new Exception("An error occurred while downloading backup file!");
    }

    /**
     * @return Client
     */
    private function setGClient(): Client
    {
        $client = new Client();
        $client->setAuthConfig($this->gClientCredentialsFilePath);
        $client->addScope(Drive::DRIVE);
        $client->setAccessType('offline');
        $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
        $client->setRedirectUri($redirectURL);
        $client->refreshToken($this->gClientRefreshToken);
        $token = $client->getAccessToken();
        $client->setAccessToken($token);

        return $client;
    }

    /**
     * @param string $fileName
     * 
     * @return object
     */
    private function uploadBackupFileToGDrive(string $fileName): object
    {
        $filePath = $this->getBackupPath($fileName);
        if (!file_exists($filePath)) {
            throw new Exception("Backup file not found!");
        }
        require_once 'google/vendor/autoload.php';
        $client = $this->setGClient();

        $service = new Drive($client);

        $file = new DriveFile();
        $file->setName($fileName);
        return $service->files->create(
            $file,
            [
                'data' => file_get_contents($filePath),
                'mimeType' => 'application/octet-stream',
                'uploadType' => 'media'
            ]
        );
    }

    /**
     * @return void
     */
    private function login(): void
    {
        $this->setLoginURL();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->loginURL);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
            "user" => $this->username,
            "pass" => $this->password,
            "goto_uri" => "/"
        ]));
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        $response = curl_exec($curl);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        if (!$response) {
            throw new Exception("An error occurred!");
        }
        $header = $this->getHeader($response, $headerSize);
        if ($header["status"]) {
            $this->sessionCookie = $this->getSessionCookie($response);
            $this->securityToken = $header["security_token"];
        }
    }

    /**
     * @param string $data
     * 
     * @return string
     */
    private function getSessionCookie(string $data): string
    {
        preg_match('/^Set-Cookie: cpsession=\s*([^;]*)/mi', $data, $matches);
        return $matches[1];
    }

    /**
     * @param string $data
     * @param int $headerSize
     * 
     * @return array
     */
    private function getHeader(string $data, int $headerSize): array
    {
        return json_decode(substr($data, $headerSize), true);
    }
}
