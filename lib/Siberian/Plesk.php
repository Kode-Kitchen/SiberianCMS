<?php

/**
 * Class Siberian_Plesk
 */

class Siberian_Plesk {

    /**
     * @var mixed|null
     */
    public $logger = null;

    /**
     * @var array
     */
    protected $config = array(
        "host" => "127.0.0.1",
        "username" => "admin",
        "password" => "changeme",
    );

    /**
     * Siberian_Plesk constructor.
     */
    public function __construct() {
        $this->logger = Zend_Registry::get("logger");
        $plesk_api = Api_Model_Key::findKeysFor("plesk");

        $this->config["host"]       = $plesk_api->getHost();
        $this->config["username"]   = $plesk_api->getUser();
        $this->config["password"]   = $plesk_api->getPassword();
    }

    /**
     * @param $hostname
     * @throws Exception
     */
    public function setCertificate($hostname) {
        try {
            /** First search in domains */
            $request = new Plesk\ListSites($this->config, array(
                "name" => $hostname
            ));
            $results = $request->process();

            // Finding domain/subdomain ID
            $subdomain_id = null;
            if(!empty($results) && is_array($results)) {
                foreach ($results as $result) {
                    if ($result["name"] == $hostname) {
                        $subdomain_id = $result["id"];
                    }
                }
            } else {
                $request = new Plesk\ListSubdomains($this->config);
                $results = $request->process();
                if(!empty($results) && is_array($results)) {
                    foreach ($results as $result) {
                        if ($result["name"] == $hostname) {
                            $subdomain_id = $result["id"];
                        }
                    }
                }
            }

            if(empty($subdomain_id)) {
                throw new Exception(__("Unable to find the given hostname %s", $hostname));
            }

            $plesk_browser = new Siberian_Plesk_Crawler($this->config["host"], $this->config["username"], $this->config["password"]);
            $plesk_browser->updateDomain($hostname, $subdomain_id);

        } catch(Exception $e) {
            throw new Exception("[Siberian_Plesk] Unable to set the Certificate with message %s", $e->getMessage());
        }
    }

    /**
     * @param $ssl_certificate
     * @return bool
     * @throws Exception
     */
    public function removeCertificate($ssl_certificate) {
        $cert_name = sprintf("%s-%s", "siberian_letsencrypt", $ssl_certificate->getHostname());

        $params_delete = array(
            "webspace" => $ssl_certificate->getHostname(),
            "cert-name" => $cert_name,
        );

        /** First try to remove an existing one */
        $this->logger->info(sprintf("[Siberian_Plesk] First try to remove an existing one ..."));
        try {
            $request = new Plesk\SSL\DeleteCertificate($this->config, $params_delete);
            $info = $request->process();

            $this->logger->info(sprintf("[Siberian_Plesk] %s", $request->error));
            if(strpos($request->error, "Permission denied.") !== false) {
                // re throw the exception
                throw new Exception(__("Permission, denied from Plesk, please use the admin account."));
            }
            $this->logger->info(sprintf("[Siberian_Plesk] %s", $request->xml_response));
            $this->logger->info(sprintf("[Siberian_Plesk] %s", print_r($info, true)));
            $this->logger->info(sprintf("[Siberian_Plesk] %s", "Certificate cleaned-up"));
        } catch(Exception $e) {
            $this->logger->info(sprintf("[Siberian_Plesk] %s", $e->getMessage()));
            throw new Exception(__("Permission, denied from Plesk, please use the admin account."));
        }

        return true;
    }

    /**
     * @param $ssl_certificate
     */
    public function updateCertificate($ssl_certificate) {
        $cert_name = sprintf("%s-%s", "siberian_letsencrypt", $ssl_certificate->getHostname());

        $params_install = array(
            "name"          => $cert_name,
            "webspace"      => $ssl_certificate->getHostname(),
            "csr"           => file_get_contents($ssl_certificate->getLast()),
            "cert"          => file_get_contents($ssl_certificate->getCertificate()),
            "pvt"           => file_get_contents($ssl_certificate->getPrivate()),
            "ca"            => file_get_contents($ssl_certificate->getChain()),
            "ip-address"    => gethostbyname($ssl_certificate->getHostname()),
        );

        $this->logger->info(sprintf("[Siberian_Plesk] Installing the certificate ..."));
        try {
            $request = new Plesk\SSL\InstallCertificate($this->config, $params_install);
            $info = $request->process();

            $this->logger->info(sprintf("[Siberian_Plesk] %s", $request->xml_response));
            $this->logger->info(sprintf("[Siberian_Plesk] %s", $request->error));
            if(strpos($request->error, "Permission denied.") !== false) {
                // re throw the exception
                throw new Exception("Permission, denied from Plesk, please use the admin account.");
            }
            $this->logger->info(sprintf("[Siberian_Plesk] %s", $request->xml_response));
            $this->logger->info(sprintf("[Siberian_Plesk] %s", print_r($info, true)));
            $this->logger->info(sprintf("[Siberian_Plesk] %s", "Certificate installed"));
        } catch(Exception $e) {
            $this->logger->info(sprintf("[Siberian_Plesk] Unable to install the certificate: %s", $e->getMessage()));
            $this->logger->info(sprintf("[Siberian_Plesk] %s", print_r($request->error, true)));
            throw new Exception(__("Permission, denied from Plesk, please use the admin account."));
        }

        return true;
    }

    /**
     * @param $ssl_certificate
     * @return bool
     * @throws Exception
     */
    public function selectCertificate($ssl_certificate) {
        // @note From here, server may reload, and then interrupt the connection
        // This is normal behavior, as it's reloading the SSL Certificate.

        // Select certificate in Plesk
        $this->logger->info(sprintf("[Siberian_Plesk] Trying to select the certificate and reloading ..."));
        if(version_compare(phpversion(), "5.3", ">")) {
            $this->logger->info(sprintf("[Siberian_Plesk] %s", "Trying to select Certificate."));
            $this->setCertificate($ssl_certificate->getHostname());
        } else {
            $this->logger->info(sprintf("[Siberian_Plesk] %s", "Unable to set the current Certificate in Plesk, please check for PHP 5.6+."));
            throw new Exception(__("Unable to select the Certificate in Plesk, please select manually the certificate name %s and save.", $cert_name));
        }

        $this->logger->info(sprintf("[Siberian_Plesk] %s", "Done with success."));

        return true;

        // Please consider you can never have the acknowledgement
    }
}