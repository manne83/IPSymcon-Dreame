<?php

declare(strict_types=1);

/**
 * Small, dependency-free Dreamehome cloud client for IP-Symcon.
 *
 * The API is undocumented and may change without notice. This implementation
 * deliberately covers only authentication, device discovery, basic state and
 * the five basic robot actions used by the module.
 */
final class DreameHomeClient
{
    private const PORT = 13267;
    private const PASSWORD_SALT = 'RAylYC%fmSKp7%Tq';
    private const USER_AGENT = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';
    private const CLIENT_AUTH = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    private const DEFAULT_TENANT = '000000';
    private const CHINA_AUTH = '1c80b3787b2266776bcdc481f37d8fa42ba10a30af81a6df-1';

    /** @var array<int, array{did:string, siid:int, piid:int}> */
    private const BASIC_PROPERTIES = [
        ['did' => '0', 'siid' => 2, 'piid' => 1],  // state
        ['did' => '1', 'siid' => 2, 'piid' => 2],  // error
        ['did' => '2', 'siid' => 3, 'piid' => 1],  // battery
        ['did' => '3', 'siid' => 3, 'piid' => 2],  // charging status
        ['did' => '5', 'siid' => 4, 'piid' => 1],  // status
        ['did' => '6', 'siid' => 4, 'piid' => 2],  // cleaning time
        ['did' => '7', 'siid' => 4, 'piid' => 3],  // cleaned area
        ['did' => '11', 'siid' => 4, 'piid' => 7]  // task status
    ];

    private string $country;
    private string $username;
    private string $password;
    private string $accessToken;
    private string $refreshToken;
    private int $tokenExpiresAt;
    private string $tenantID;
    private string $userID;
    private string $deviceID = '';
    private string $deviceName = '';
    private string $model = '';
    private string $bindDomain = '';
    private int $requestID;

    public function __construct(
        string $country,
        string $username,
        string $password,
        array $session = []
    ) {
        $country = strtolower(trim($country));
        if ($country !== 'de') {
            throw new InvalidArgumentException('Unsupported Dreamehome region');
        }

        $this->country = $country;
        $this->username = trim($username);
        $this->password = $password;
        $this->accessToken = (string) ($session['accessToken'] ?? '');
        $this->refreshToken = (string) ($session['refreshToken'] ?? '');
        $this->tokenExpiresAt = (int) ($session['tokenExpiresAt'] ?? 0);
        $this->tenantID = (string) ($session['tenantID'] ?? self::DEFAULT_TENANT);
        $this->userID = (string) ($session['userID'] ?? '');
        $this->requestID = random_int(1, 10000);
    }

    public function login(): void
    {
        if ($this->accessToken !== '' && $this->tokenExpiresAt > time() + 60) {
            return;
        }

        if ($this->refreshToken !== '' && $this->authenticate(true)) {
            return;
        }

        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('Dreamehome credentials are missing');
        }

        if (!$this->authenticate(false)) {
            throw new RuntimeException('Dreamehome login failed');
        }
    }

    /** @return array<string, mixed> */
    public function exportSession(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'tokenExpiresAt' => $this->tokenExpiresAt,
            'tenantID' => $this->tenantID,
            'userID' => $this->userID
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getVacuumDevices(): array
    {
        $response = $this->apiRequest('dreame-user-iot/iotuserbind/device/listV2');
        $records = $response['data']['page']['records'] ?? [];
        if (!is_array($records)) {
            throw new RuntimeException('Unexpected device-list response');
        }

        return array_values(array_filter(
            $records,
            static fn (mixed $device): bool => is_array($device)
                && str_contains((string) ($device['model'] ?? ''), '.vacuum.')
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     * @return array<string, mixed>
     */
    public function selectDevice(array $devices, string $requestedDeviceID = ''): array
    {
        $requestedDeviceID = trim($requestedDeviceID);
        if ($requestedDeviceID !== '') {
            foreach ($devices as $device) {
                if ((string) ($device['did'] ?? '') === $requestedDeviceID) {
                    return $device;
                }
            }
            throw new RuntimeException('Configured device ID was not found');
        }

        $x60Devices = array_values(array_filter(
            $devices,
            static function (array $device): bool {
                $text = strtolower(
                    (string) ($device['model'] ?? '') . ' ' .
                    (string) ($device['customName'] ?? '') . ' ' .
                    (string) ($device['deviceInfo']['displayName'] ?? '')
                );
                return str_contains($text, 'x60') || str_contains($text, 'r6001');
            }
        ));

        if (count($x60Devices) === 1) {
            return $x60Devices[0];
        }
        if (count($devices) === 1) {
            return $devices[0];
        }
        if (count($devices) === 0) {
            throw new RuntimeException('No vacuum robot was found');
        }

        throw new RuntimeException('More than one robot was found; configure the device ID');
    }

    /** @param array<string, mixed> $device */
    public function prepareDevice(array $device): void
    {
        $this->deviceID = (string) ($device['did'] ?? '');
        if ($this->deviceID === '') {
            throw new RuntimeException('Robot has no device ID');
        }

        $this->model = (string) ($device['model'] ?? '');
        $this->deviceName = (string) ($device['customName'] ?? '');
        if ($this->deviceName === '') {
            $this->deviceName = (string) ($device['deviceInfo']['displayName'] ?? $this->model);
        }
        $this->bindDomain = (string) ($device['bindDomain'] ?? '');

        $response = $this->apiRequest(
            'dreame-user-iot/iotuserbind/device/info',
            ['did' => $this->deviceID]
        );
        $info = $response['data'] ?? [];
        if (is_array($info)) {
            $this->model = (string) ($info['model'] ?? $this->model);
            $this->bindDomain = (string) ($info['bindDomain'] ?? $this->bindDomain);
            $this->userID = (string) ($info['masterUid'] ?? $this->userID);
        }

        if ($this->bindDomain === '') {
            throw new RuntimeException('Robot cloud endpoint is missing');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getBasicState(): array
    {
        $result = $this->sendCommand('get_properties', self::BASIC_PROPERTIES);
        if (!is_array($result)) {
            throw new RuntimeException('Unexpected robot-state response');
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function start(): array
    {
        return $this->action(2, 1);
    }

    /** @return array<string, mixed> */
    public function pause(): array
    {
        return $this->action(2, 2);
    }

    /** @return array<string, mixed> */
    public function returnToDock(): array
    {
        return $this->action(3, 1);
    }

    /** @return array<string, mixed> */
    public function stop(): array
    {
        return $this->action(4, 2);
    }

    /** @return array<string, mixed> */
    public function locate(): array
    {
        return $this->action(7, 1);
    }

    public function getDeviceID(): string
    {
        return $this->deviceID;
    }

    public function getDeviceName(): string
    {
        return $this->deviceName;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function authenticate(bool $withRefreshToken): bool
    {
        $grant = $withRefreshToken ? 'refresh_token' : 'password';
        $fields = [
            'platform' => 'IOS',
            'scope' => 'all',
            'grant_type' => $grant
        ];
        if ($withRefreshToken) {
            $fields['refresh_token'] = $this->refreshToken;
        } else {
            $fields['username'] = $this->username;
            $fields['password'] = md5($this->password . self::PASSWORD_SALT);
            $fields['type'] = 'account';
        }

        [$status, $body] = $this->httpPost(
            $this->getBaseURL() . '/dreame-auth/oauth/token',
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            $this->loginHeaders()
        );

        if ($status !== 200) {
            if ($withRefreshToken) {
                $this->accessToken = '';
                $this->refreshToken = '';
                $this->tokenExpiresAt = 0;
            }
            return false;
        }

        $data = $this->decodeJSON($body);
        $accessToken = (string) ($data['access_token'] ?? '');
        if ($accessToken === '') {
            return false;
        }

        $this->accessToken = $accessToken;
        $this->refreshToken = (string) ($data['refresh_token'] ?? $this->refreshToken);
        $this->tokenExpiresAt = time() + max(60, (int) ($data['expires_in'] ?? 3600)) - 120;
        $this->userID = (string) ($data['uid'] ?? $this->userID);
        $this->tenantID = (string) ($data['tenant_id'] ?? $this->tenantID);
        return true;
    }

    /**
     * @param array<string, mixed>|null $parameters
     * @return array<string, mixed>
     */
    private function apiRequest(string $path, ?array $parameters = null, bool $allowReauthentication = true): array
    {
        $this->login();
        $payload = $parameters === null
            ? ''
            : json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        [$status, $body] = $this->httpPost(
            $this->getBaseURL() . '/' . ltrim($path, '/'),
            $payload,
            $this->apiHeaders()
        );

        if ($status === 401 && $allowReauthentication && $this->refreshToken !== '') {
            $this->accessToken = '';
            $this->tokenExpiresAt = 0;
            $this->login();
            return $this->apiRequest($path, $parameters, false);
        }
        if ($status !== 200) {
            throw new RuntimeException(sprintf('Dreamehome returned HTTP %d', $status));
        }

        $data = $this->decodeJSON($body);
        if (isset($data['code']) && (int) $data['code'] !== 0) {
            throw new RuntimeException(sprintf('Dreamehome returned API code %d', (int) $data['code']));
        }
        return $data;
    }

    /**
     * @param mixed $parameters
     * @return mixed
     */
    private function sendCommand(string $method, mixed $parameters): mixed
    {
        if ($this->deviceID === '' || $this->bindDomain === '') {
            throw new LogicException('Robot has not been prepared');
        }

        $id = ++$this->requestID;
        $payload = [
            'did' => $this->deviceID,
            'id' => $id,
            'data' => [
                'did' => $this->deviceID,
                'id' => $id,
                'method' => $method,
                'params' => $parameters
            ]
        ];

        $hostPrefix = explode('.', $this->bindDomain)[0];
        $suffix = $hostPrefix !== '' ? '-' . $hostPrefix : '';
        $response = $this->apiRequest('dreame-iot-com' . $suffix . '/device/sendCommand', $payload);
        if (!array_key_exists('result', $response['data'] ?? [])) {
            throw new RuntimeException('Robot command returned no result');
        }
        return $response['data']['result'];
    }

    /** @return array<string, mixed> */
    private function action(int $siid, int $aiid): array
    {
        $result = $this->sendCommand('action', [
            'did' => $this->deviceID,
            'siid' => $siid,
            'aiid' => $aiid,
            'in' => []
        ]);
        if (is_array($result) && array_is_list($result)) {
            $result = $result[0] ?? [];
        }
        if (!is_array($result) || (int) ($result['code'] ?? -1) !== 0) {
            throw new RuntimeException('Robot rejected the command');
        }
        return $result;
    }

    /** @return array<string, string> */
    private function loginHeaders(): array
    {
        $headers = [
            'Accept' => '*/*',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept-Language' => 'en-US;q=0.8',
            'Accept-Encoding' => 'gzip, deflate',
            'User-Agent' => self::USER_AGENT,
            'Dreame-Rlc' => self::CLIENT_AUTH,
            'Tenant-Id' => $this->tenantID !== '' ? $this->tenantID : self::DEFAULT_TENANT
        ];
        if ($this->country === 'cn') {
            $headers['Dreame-Auth'] = self::CHINA_AUTH;
        }
        return $headers;
    }

    /** @return array<string, string> */
    private function apiHeaders(): array
    {
        return [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
            'Accept-Language' => 'en-US;q=0.8',
            'Accept-Encoding' => 'gzip, deflate',
            'User-Agent' => self::USER_AGENT,
            'Dreame-Rlc' => self::CLIENT_AUTH,
            'Tenant-Id' => $this->tenantID !== '' ? $this->tenantID : self::DEFAULT_TENANT,
            'Authorization' => $this->accessToken
        ];
    }

    private function getBaseURL(): string
    {
        return sprintf('https://%s.iot.dreame.tech:%d', $this->country, self::PORT);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0:int, 1:string}
     */
    private function httpPost(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is not available');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize HTTPS connection');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException('Dreamehome connection failed: ' . $error);
        }
        return [$status, (string) $response];
    }

    /** @return array<string, mixed> */
    private function decodeJSON(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Dreamehome returned invalid JSON', 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Dreamehome returned an invalid response');
        }
        return $data;
    }
}
