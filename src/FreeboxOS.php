<?php
/*=============================================
=            Freebox OS API Client            =
=============================================*/
/*
    It's a DRAFT, so don't use it for the moment.
    (c) 2013 Christophe VERGNE.
    Under MIT License

    RestAPIClient is based on the work of Travis Dent ( https://github.com/tcdent )
*/

class FreeboxOS {
    public $options;
    private $api_full_base_url;

    // APP data
    public $app;

    // Freebox API Vars
    private $uid;
    public $device_name;
    private $api_version='1.0';
    private $api_base_url='/api/';
    public $device_type;
    private $app_token;
    private $track_id;
    public $auth_status;
    private $challenge;
    private $password;
    public $logged_in=false;
    private $session_token;
    public $permissions;

    public function __construct($app=array(), $options=array())
    {
        // Check primary app informations
        $this->app = array_merge(array(
            'app_id' => NULL,
            'app_name' => NULL,
            'app_version' => NULL,
            'device_name' => NULL
        ), $app);
        foreach ($this->app as $key => $val) {
            if (empty($val)) {
                throw new Exception('the value of ' . $key . ' is missing');
            }
        }

        // Merge main options
        $this->options = array_merge(array(
            'freebox_ip' => 'http://mafreebox.freebox.fr',
            'freebox_local' => 'http://mafreebox.freebox.fr',
            'monitor_wait' => 5, // in seconds
            'rest' => array(
                'headers' => array(
                    'Content-Type' => 'application/json'
                )
            )
        ), $options);

        // Define Base URL
        $this->defineBaseUrls();

        // Define Token
        if (isset($this->options['app_token']) && !empty($this->options['app_token'])) {
            $this->app_token = $this->options['app_token'];
        }

        // Init API Client
        $this->API = new RestAPIClient($this->options['rest']);
    }

    /*==========  Login  ==========*/
    public function login_Autolog()
    {
        if (!$this->app_token) {
            $this->login_Authorize();
            $this->login_Monitortrack();
        }

        $this->login_Challenge();
        $this->login_Session();
    }

    public function login_Authorize()
    {
        $this->switchToLocal(true);
        $request = $this->API->post('login/authorize/', $this->app);
        $this->switchToLocal(false);

        if ($request->info->http_code == 200 && $request->response->success) {
            $this->app_token = $request->response->result->app_token;
            $this->track_id = $request->response->result->track_id;
        }
        else if (!$request->response->success) {
            $this->error($request);
        }

        return $request;
    }

    public function login_Monitortrack()
    {
        $this->login_Track();
        if ($this->auth_status == 'pending') {
            sleep($this->options['monitor_wait']);
            $this->login_Monitortrack();
        }
    }

    public function login_Track()
    {
        if ($this->track_id) {
            $this->switchToLocal(true);
            $request = $this->API->get('login/authorize/' . $this->track_id);
            $this->switchToLocal(false);

            if ($request->info->http_code == 200 && $request->response->success) {
                $this->challenge = $request->response->result->challenge;
                $this->auth_status = $request->response->result->status;
                return $this->auth_status;
            }
            else if (!$request->response->success) {
                $this->error($request);
                return false;
            }
        }
        else {
            throw new Exception('Missing Track ID. Run authorize first or pass an app_token.');
        }

        return false;
    }

    public function login_Challenge()
    {
        $request = $this->API->get('login/');

        if ($request->info->http_code == 200 && $request->response->success) {
            $this->logged_in = $request->response->result->logged_in;
            if (isset($request->response->result->challenge)) {
                $this->challenge = $request->response->result->challenge;
            }

            return true;
        }
        else if (!$request->response->success) {
            $this->error($request);
        }

        return false;
    }

    public function login_Session()
    {
        $request = $this->API->post('login/session/', array(
            'app_id' => $this->app['app_id'],
            'password' => $this->setPassword()
        ));
        if ($request->info->http_code == 200 && $request->response->success) {
            $this->session_token = $request->response->result->session_token;

            $this->setSession();

            if ($request->response->result->permissions) {
                $this->permissions = $request->response->result->permissions;
            }

            return true;
        }
        else if (!$request->response->success) {
            $this->error($request);
        }

        return false;
    }

    /*==========  Downloads  ==========*/
    public function downloads_List()
    {
        $this->checkPermission('downloader');

        $request = $this->API->get('downloads/');
        if ($request->info->http_code == 200 && $request->response->success) {
            return $request->response;
        }
        else if (!$request->response->success) {
            $this->error($request);
        }
    }


    /*==========  UTILITIES  ==========*/
    public function checkPermission($id=NULL)
    {
        if (!$this->logged_in) {
            $this->login_Challenge();
            $this->login_Session();
        }
        if ($id && !$this->permissions->{$id}) {
            throw new Exception('Access denied for this app to ' . $id);
        }
    }

    public function checkApiVersion()
    {
        $request = $this->API->request($this->options['freebox_ip'] . '/api_version', 'GET', array(), array(), false);
        if ($request->info->http_code == 200) {
            $this->uid = $request->response->uid;
            $this->device_name = $request->response->device_name;
            $this->api_version = $request->response->api_version;
            $this->api_base_url = $request->response->api_base_url;
            $this->device_type = $request->response->device_type;

            $this->defineBaseUrls();

            return true;
        }
        else {
            return $request;
        }
    }

    private function defineBaseUrls()
    {
        $this->api_full_base_url = $this->api_base_url . 'v' . intval($this->api_version);

        $this->options['rest']['base_url'] = $this->options['freebox_ip'] . $this->api_full_base_url;
        $this->options['rest']['base_url_local'] = $this->options['freebox_local'] . $this->api_full_base_url;

        if (isset($this->API)) {
            $this->API->options['base_url'] = $this->options['rest']['base_url'];
            $this->API->options['base_url_local'] = $this->options['rest']['base_url_local'];
        }
    }

    private function switchToLocal($state=false)
    {
        $this->API->options['switch_base_url'] = $state;
    }

    private function setPassword()
    {
        if (!$this->challenge) {
            $this->login_Challenge();
        }
        if ($this->app_token && $this->challenge) {
            $this->password = hash_hmac('sha1', $this->challenge, $this->app_token);
            return $this->password;
        }
        else {
            throw new Exception('Error Password set, missing app_token or challenge');

        }
    }

    private function setSession()
    {
        if ($this->session_token) {
            $session_auth_headers = $this->session_token;
            $this->options['rest']['headers']['X-Fbx-App-Auth'] = $session_auth_headers;
            $this->API->options['headers']['X-Fbx-App-Auth'] = $session_auth_headers;
        }
    }

    private function error($request, $addmessage='')
    {
        var_dump($request);
        throw new Exception($addmessage . ' [' . $request->response->error_code . '] ' . $request->response->msg);
    }
}

class RestAPIClient
{
    public $options;
    public $handle; // cURL ressource

    public $response;
    public $headers;
    public $info;
    public $error;

    public function __construct($options=array())
    {
        $this->options = array_merge(array(
            'headers' => array(),
            'curl_options' => array(),
            'base_url' => NULL,
            'base_url_local' => NULL,
            'switch_base_url' => false,
            'format' => NULL,
            'username' => NULL,
            'password' => NULL
        ), $options);
    }

    public function get($url, $parameters=array(), $headers=array())
    {
        return $this->request($url, 'GET', $parameters, $headers);
    }

    public function post($url, $parameters=array(), $headers=array())
    {
        return $this->request($url, 'POST', $parameters, $headers);
    }

    public function put($url, $parameters=array(), $headers=array())
    {
        $parameters['_method'] = "PUT";
        return $this->post($url, $parameters, $headers);
    }

    public function delete($url, $parameters=array(), $headers=array())
    {
        $parameters['_method'] = "DELETE";
        return $this->post($url, $parameters, $headers);
    }

    public function parse_response($response)
    {
        $headers = array();
        $http_ver = strtok($response, "\n");

        while($line = strtok("\n")){
            if(strlen(trim($line)) == 0) break;

            list($key, $value) = explode(':', $line, 2);
            $key = trim(strtolower(str_replace('-', '_', $key)));
            $value = trim($value);
            if(empty($headers[$key])){
                $headers[$key] = $value;
            }
            elseif(is_array($headers[$key])){
                $headers[$key][] = $value;
            }
            else {
                $headers[$key] = array($headers[$key], $value);
            }
        }

        $this->headers = (object) $headers;
        $this->response = json_decode(strtok(""));
    }

    public function request($url, $method='GET', $parameters=array(), $headers=array(), $use_base_url=true)
    {
        $client = clone $this;

        $client->url = $url;
        $client->handle = curl_init();

        $curl_options = array(
            CURLOPT_HEADER => TRUE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT => 20
        );

        // Mix default headers and custom request headers
        if(count($client->options['headers']) || count($headers)) {
            $curl_options[CURLOPT_HTTPHEADER] = array();

            $headers = array_merge($client->options['headers'], $headers);

            foreach ($headers as $key => $value) {
                $curl_options[CURLOPT_HTTPHEADER][] = sprintf("%s:%s", $key, $value);
            }
        }

        // Format query
        if (count($parameters)) {
            $curl_options[CURLOPT_POSTFIELDS] = json_encode($parameters);
        }

        // Define Base URL
        if ($use_base_url) {
            if ($client->options['switch_base_url'] && $client->options['base_url_local']) {
                $base_url = $client->options['base_url_local'];
            }
            else {
                $base_url = $client->options['base_url'];
            }
            if($base_url){
                if($client->url[0] != '/' || substr($base_url, -1) != '/')
                    $client->url = '/' . $client->url;
                $client->url = $base_url . $client->url;
            }
        }
        $curl_options[CURLOPT_URL] = $client->url;

        // Additional CURL Options
        if($client->options['curl_options']){
            // array_merge would reset our numeric keys.
            foreach($client->options['curl_options'] as $key => $value){
                $curl_options[$key] = $value;
            }
        }
        curl_setopt_array($client->handle, $curl_options);

        // Exec and parse request
        $client->parse_response(curl_exec($client->handle));
        $client->info = (object) curl_getinfo($client->handle);
        $client->error = curl_error($client->handle);

        curl_close($client->handle);
        return $client;
    }
}