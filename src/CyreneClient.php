<?php

namespace Cyrene;
/**
 * Created by PhpStorm.
 * User: felix
 * Date: 12/01/2017
 * Time: 01:39
 */
class CyreneClient
{
    protected $endpointBase;
    protected $token;

    protected $clientId = 'cyrene_dev_client';
    protected $clientSecret = 'CYRENE_DEV_CLIENT_SECRET';

    public function __construct(string $clientIdentifier, string $clientId, string $clientSecret)
    {
        $this->endpointBase = 'https://' . $clientIdentifier . '.cyrene.io';
        $this->token = NULL;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    public function createNewCyreneTrialAccount(string $clientName, string $adminEmail)
    {
        if (is_null($clientName) || is_null($adminEmail))
        {
            return;
        }

        $registrationData = [
            'clientname' => $clientName,
            'adminEmail' => $adminEmail
        ];
        $fullEndpointUrl = 'https://master.cyrene.io/register';
        $this->ensureToken();
        $response = $this->sendPostRequest($this->token, $fullEndpointUrl, $registrationData);
        return $response;
    }

    public function createEntry(string $modelName, array $data)
    {
        $this->ensureToken();
        $relativeUrl = '/Main/' . $modelName;
        $response = $this->sendProtectedPostRequest($this->token, $relativeUrl, $data);
        return $response;
    }

    public function updateEntry(string $modelName, string $entryId, array $data)
    {
        $this->ensureToken();
        $relativeUrl = '/Main/' . $modelName . '/update/' . $entryId;
        $response = $this->sendProtectedPostRequest($this->token, $relativeUrl, $data);
        return $response;
    }

    public function getEntries(string $modelName, string $options = '')
    {
        $addOptions = '';
        if($options != '') {
            $addOptions = '?'.$options;
        }

        $this->ensureToken();
        $relativeUrl = '/Main/' . $modelName . $addOptions;
        $response = $this->sendProtectedGetRequest($this->token, $relativeUrl);
        return $response->data;
    }

    public function getEntry(string $modelName, string $id)
    {
        $this->ensureToken();
        $relativeUrl = '/Main/' . $modelName . '/' . $id;
        $response = $this->sendProtectedGetRequest($this->token, $relativeUrl);
        return $response->data[0];
    }

    public function getTemplateNameFromUri(string $requestUri) : string
    {
        $templateName = strtolower($requestUri);
        $templateName = str_replace(['/', '.php'], '', $templateName);
        if ($templateName === '')
        {
            $templateName = 'index';
        }
        return $templateName;
    }
    public function savePage(string $websiteId, string $editPass, array $websiteChanges)
    {
        $websiteDoc = $this->getEntry('Websites', $websiteId);
        if ($editPass === $websiteDoc->editPass)
        {
            return $this->updateEntry('Websites', $websiteId, $websiteChanges);
        }
    }
    public function loadPage(string $websiteId, string $templateName, array $dataStrings = [], bool $includeMeta = true)
    {
        require_once ('VisibleStdClass.php');
        require_once ('Pagination.php');

        // the following request could be made fast by projecting on the templatename and the metadata
        $websiteDoc = $this->getEntry('Websites', $websiteId);
        $metaDataObject = $websiteDoc->meta;
        $metaDataObject->_page = [];
        $dataDocs = [];
        if (count($dataStrings) > 0)
        {
            foreach ($dataStrings as $dataString)
            {
                $this->ensureToken();
                $response = $this->sendProtectedGetRequest($this->token, $dataString);
                if (property_exists($response, 'lastPage') && property_exists($response, 'count'))
                {
                    $metaDataObject->_page[] = new Pagination($response->lastPage, $response->count);
                }
                else
                {
                    $metaDataObject->_page[] = new Pagination(1, 10);
                }
                $dataDocs[] = $response->data;
            }
        }


        return [
            new VisibleStdClass($metaDataObject, 'meta'),
            new VisibleStdClass((object)$websiteDoc->templates->{$templateName}, 'content'),
            $dataDocs
        ];
    }

    private function ensureToken()
    {
        if ($this->token == NULL)
        {
            $tokenData = $this->getAccessTokenViaClientCredentials($this->clientId, $this->clientSecret);
            $this->token = $tokenData->access_token;
        }
    }

    private function getAccessTokenViaClientCredentials(string $clientId, string $clientSecret)
    {
        $endpoint = $this->endpointBase . "/oauth2/token";

        // Use one of the parameter configurations listed at the top of the post
        $params = [
            "client_id"     => $clientId,
            "client_secret" => $clientSecret,
            "grant_type"    => "client_credentials",
        ];

        $curl = curl_init($endpoint);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HEADER, 'Content-Type: application/x-www-form-urlencoded');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Cookie: XDEBUG_SESSION=XDEBUG_ECLIPSE"));
        // Remove comment if you have a setup that causes ssl validation to fail
        //curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $postData = $this->toPostData($params);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);

        $json_response = curl_exec($curl);

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // evaluate for success response
        if ($status != 200)
        {
            throw new Exception("Error: call to URL $endpoint failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . "\n");
        }
        curl_close($curl);

        $result = json_decode($json_response);
        //var_dump($json_response);
        return $result;
    }

    /**
     * @param $params
     * @return string
     */
    private function toPostData($params): string
    {
        $postData = "";

        //This is needed to properly form post the credentials object
        foreach ($params as $k => $v)
        {
            $postData .= $k . '=' . urlencode($v) . '&';
        }

        $postData = rtrim($postData, '&');

        return $postData;
    }

    private function sendPostRequest(string $token, string $fullEndpointUrl, array $data)
    {
        $endpoint = $fullEndpointUrl;

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ];

        $curl = curl_init($endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Cookie: XDEBUG_SESSION=XDEBUG_ECLIPSE"));

        $postData = $this->toPostData($data);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);

        $json_response = curl_exec($curl);

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // evaluate for success response
        if ($status != 200)
        {
            var_dump($headers);
            throw new Exception("Error: call to URL $endpoint failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . "\n");
        }
        curl_close($curl);

        return json_decode($json_response);
    }

    private function sendProtectedPostRequest(string $token, string $relativeUrl, array $data)
    {
        $endpoint = $this->endpointBase . $relativeUrl;

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest',
        ];

        $curl = curl_init($endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $postData = json_encode($data);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);

        $json_response = curl_exec($curl);

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // evaluate for success response
        if ($status != 200)
        {
            var_dump($headers);
            $curl_error = curl_error($curl);
            $curl_errno = curl_errno($curl);
            $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $header = substr($json_response, 0, $header_size);
            $body = substr($json_response, $header_size);
            throw new Exception("Error: call to URL $endpoint failed with status $status, response $json_response, curl_error " . $curl_error . ", curl_errno " . $curl_errno . "\n");
        }
        curl_close($curl);

        return json_decode($json_response);
    }

    private function sendProtectedGetRequest(string $token, string $relativeUrl)
    {
        $endpoint = $this->endpointBase . $relativeUrl;

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest',
        ];

        $curl = curl_init($endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $json_response = curl_exec($curl);

        //var_dump($json_response);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // evaluate for success response
        if ($status != 200)
        {
            var_dump($headers);
            throw new Exception("Error: call to URL $endpoint failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl) . "\n");
        }
        curl_close($curl);

        return json_decode($json_response);
    }
}

//$c = new CyreneClient('osteo', 'DEFAULT_CLIENT_ID', 'RANDOM_SECRET');
//$entries = $c->getEntry('Websites', '591b1e6cababe403c364cc41');

//$c = new CyreneClient('lrx', 'cyrene_dev_client_x', 'geheim');
//$entries = $c->getEntries('Blogs');
//var_dump($entries);