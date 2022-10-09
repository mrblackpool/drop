<?php
// $workingDir = '/home/henri/dropcatch/';
$configFile = 'config.ini';
$config = parse_ini_file($configFile);
$tag = $config['tag'];
$password = $config['password'];
$registrant = $config['registrant'];
$domainsFile = $config['domainsfile'];
$contactID = $config['contactID'];
$contactName = $config['contactName'];
$contactStreet = $config['contactStreet'];
$contactCity = $config['contactCity'];
$contactSp = $config['contactSp'];
$contactPc = $config['contactPc'];
$contactCc = $config['contactCc'];
$contactVoice = $config['contactVoice'];
$contactEmail = $config['contactEmail'];
$aggressive = (int) $config['aggressive'];

$nullDate = date_create("1970-01-01 00:00:00", timezone_open("UTC"));
// Send the domain create request this number of milliseconds before drop time
$msAdvance = $config['msadvance'];
// Number of registration attempts to make per domain
$registrationAttempts = $config['registrationattempts'];
$connectURL = $config['connectURL'];
$epp = new Epp();

class domain
{

    public string $name;

    public DateTime $dropTime;
}

$oldDomainsFileTimestamp = 0;

while (1) {
    // Get modified time of domains file, only process it if it's different.
    clearstatcache();
    $domainFileTimestamp = filemtime($domainsFile);
    if ($domainFileTimestamp !== $oldDomainsFileTimestamp) {
        $oldDomainsFileTimestamp = $domainFileTimestamp;
        $waitingMessageShown = false;

        print "Connecting to EPP...";
        // $response=$epp->connect('tls://epp.nominet.org.uk', 700);
        $response = $epp->connect($connectURL, 700);
        if ($response) {
            print "Success" . PHP_EOL;
        } else {
            print "Error" . PHP_EOL;
            exit();
        }

        print "Logging in to EPP...";
        $response = $epp->login($tag, $password);
        if ($response) {
            print "Success" . PHP_EOL;
        } else {
            print "Error" . PHP_EOL;
            exit();
        }

        print "Creating Contact...";
        $response = $epp->createContact($contactID, $contactName, $contactStreet, $contactCity, $contactSp, $contactPc, $contactCc, $contactVoice, $contactEmail);
        print $response . PHP_EOL;

        $targetDomains = file($domainsFile);
        $domains = null;
        // Read list of domains, check drop time via EPP and place them into an array of objects
        foreach ($targetDomains as $targetDomain) {
            $domainObj = new domain();
            $domainObj->name = trim($targetDomain);

            $dropTime = $epp->getDropTime($domainObj->name);
            if ($dropTime === false) {
                $domainObj->dropTime = $nullDate;
            } else {
                $domainObj->dropTime = $dropTime;
            }
            $domains[] = $domainObj;
        }

        // Sort domains by drop time
        usort($domains, 'compareTimestamp');

        /*
         * print "Logging out of EPP...";
         * $epp->logout();
         * print "OK" . PHP_EOL;
         */

        $currentTarget = 0;
        $totalTargets = sizeof($domains);

        while ($currentTarget < $totalTargets) {
            if (! (($domains[$currentTarget]->dropTime) == $nullDate)) {
                print "Next target is " . $domains[$currentTarget]->name . PHP_EOL;

                $longWait = true;
                while ($longWait) {
                    $now = new DateTime("now", new DateTimeZone('UTC'));
                    $delay = $domains[$currentTarget]->dropTime->getTimestamp() - $now->getTimestamp();
                    if ($delay > 2700) // If drop is happening in over 45 minutes
                    {
                        sleep(1800); // Sleep for 30 minutes
                        print "Sending keep-alive." . PHP_EOL;
                        $epp->keepAlive(); // Send keep-alive/hello to Nominet
                    } else {
                        $longWait = false;
                    }
                }
                $delay = $delay - 60;
                if ($delay > 0) {
                    print "Sleeping for $delay seconds (one minute before " . $domains[$currentTarget]->dropTime->format('Y-m-d H:i:s:u') . ")" . PHP_EOL;
                    sleep($delay);
                }

                /*
                 * print "Connecting to EPP...";
                 * // $response=$epp->connect('tls://epp.nominet.org.uk', 700);
                 * $response = $epp->connect($connectURL, 700);
                 * if ($response) {
                 * print "Success" . PHP_EOL;
                 * } else {
                 * print "Error" . PHP_EOL;
                 * exit();
                 * }
                 *
                 * print "Logging in to EPP...";
                 * $response = $epp->login($tag, $password);
                 * if ($response) {
                 * print "Success" . PHP_EOL;
                 * } else {
                 * print "Error" . PHP_EOL;
                 * exit();
                 * }
                 */

                // Drop time in milliseconds
                $msDropTime = dateTimeToMilliseconds($domains[$currentTarget]->dropTime);
                // $msDropTime=(int) (microtime(true) * 1000);
                print "Waiting for the precise time" . PHP_EOL;
                $attemptMade = false;
                while (! $attemptMade) {
                    // If current time in milliseconds is greater than or equalal to droptime minus our offset
                    if ((int) (microtime(true) * 1000) >= ($msDropTime - $msAdvance)) {
                        if (! $aggressive) {
                            /*
                             * for ($i = 0; $i < $registrationAttempts; $i ++) {
                             * $epp->createDomain($domains[$currentTarget]->name, $password, $registrant, $i);
                             * }
                             */
                            // $now=new DateTime("now", new DateTimeZone('UTC'));
                            // print $now->format("H:i:s:u")." Domain status: ".$epp->getStatus($domains[$currentTarget]->name).PHP_EOL;
                            $epp->createDomain2($domains[$currentTarget]->name, $registrant, $registrationAttempts);
                        } else {
                            $response = $epp->createDomainAggressively($domains[$currentTarget]->name, $password, $registrant, $registrationAttempts, 0);
                            print "$registrationAttempts attempts to register " . $domains[$currentTarget]->name . " have been made" . PHP_EOL;
                            $successResponse = 'result code="1000"';

                            $successPosition = strpos($response, $successResponse);

                            if ($successPosition !== false) {
                                $pattern = '/clTRID>(.*?)</';
                                preg_match($pattern, $response, $matches, PREG_UNMATCHED_AS_NULL, $successPosition);
                                $successfulTX = $matches[1];
                                print "Success with $successfulTX" . PHP_EOL;
                            } else {
                                print "Failed - Now go home and get your fucking shine box!" . PHP_EOL;
                            }
                        }
                        $attemptMade = true;
                    } else {
                        // Sleep for 1 millisecond
                        usleep(1000);
                    }
                }
                // $epp->logout();
            } else {
                print "Skipping " . $domains[$currentTarget]->name . " due to invalid drop date" . PHP_EOL;
            }
            $currentTarget ++;
        }
    } else {
        if (! $waitingMessageShown) {
            print "Waiting for $domainsFile to change" . PHP_EOL;
            $waitingMessageShown = true;
        }
        sleep(5);
    }
    $epp->logout();
}

function compareTimestamp($obj1, $obj2)
{
    if ($obj1->dropTime < $obj2->dropTime) {
        return - 1;
    }
    if ($obj1->dropTime == $obj2->dropTime) {
        return 0;
    }
    if ($obj1->dropTime > $obj2->dropTime) {
        return 1;
    }
}

function dateTimeToMilliseconds(DateTime $dateTime)
{
    $secs = $dateTime->getTimestamp(); // Gets the seconds
    $millisecs = $secs * 1000; // Converted to milliseconds
    $millisecs += $dateTime->format("u") / 1000; // Microseconds converted to seconds
    return $millisecs;
}

class Epp
{

    private $connection;

    // Connect to the EPP
    public function connect($address = "testbed-epp.nominet.org.uk", $port = 8700)
    {
        $timeout = @ini_get('default_socket_timeout');
        $flags = null;
        $options = array(
            'ssl' => array(
                'verify_peer_name' => false,
                'ciphers' => 'HIGH:TLSv1.2:TLSv1.1:!TLSv1.0:!SSLv3:!SSLv2',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            )
        );
        $context = stream_context_create($options);
        $flags = STREAM_CLIENT_CONNECT;

        $this->connection = stream_socket_client($address . ':' . $port, $errno, $errstr, $timeout, $flags, $context);

        if ($this->connection == FALSE) {
            return (false);
        }

        stream_set_blocking($this->connection, (int) false);
        stream_set_write_buffer($this->connection, 0);

        $this->readEPP($this->connection);
        return (true);
    }

    // Login to the EPP
    public function login($tag, $password)
    {
        $loginXML = '<?xml version="1.0" encoding="UTF-8"?>
  <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
      <login>
        <clID>' . $tag . '</clID>
        <pw>' . $password . '</pw>
        <options>
          <version>1.0</version>
          <lang>en</lang>
        </options>
        <svcs>
           <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
           <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
           <objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
           <svcExtension>
             <extURI>http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0</extURI>
             <extURI>http://www.nominet.org.uk/epp/xml/domain-nom-ext-1.0</extURI>
           </svcExtension>
        </svcs>
      </login>
    </command>
  </epp>';

        $response = $this->sendEPP($loginXML);
        if (stripos($response, "Command Completed Successfully") !== false) {
            return (true);
        } else {
            return (false);
        }
    }

    // Logout of the EPP
    public function logout()
    {
        $logoutXML = '<?xml version="1.0" encoding="UTF-8"?>
  <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
    <command>
       <logout/>
    </command>
  </epp>';

        return $this->sendEPP($logoutXML);
    }

    // Create a domain
    public function createDomain($domain, $password, $registrant, $transactionID)
    {
        $createDomainXML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\"
   xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
   xsi:schemaLocation=\"urn:ietf:params:xml:ns:epp-1.0
   epp-1.0.xsd\">
   <command>
     <create>
       <domain:create
         xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\"
         xsi:schemaLocation=\"urn:ietf:params:xml:ns:domain-1.0
         domain-1.0.xsd\">
         <domain:name>" . $domain . "</domain:name>
         <domain:period unit=\"y\">1</domain:period>
         <domain:registrant>" . $registrant . "</domain:registrant>
         <domain:authInfo>
           <domain:pw>0</domain:pw>
         </domain:authInfo>
       </domain:create>
     </create>
     <clTRID>" . $transactionID . "</clTRID>
   </command>
</epp>";

        return $this->sendEPP($createDomainXML, "Command completed successfully");
    }

    public function createDomain2($domain, $registrant, $maxAttempts)
    {
        $i = 0;
        $TXID = 'TX' . $i;
        $done = false;

        while (! $done) {
            $createDomainXML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\"
   xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
   xsi:schemaLocation=\"urn:ietf:params:xml:ns:epp-1.0
   epp-1.0.xsd\">
   <command>
     <create>
       <domain:create
         xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\"
         xsi:schemaLocation=\"urn:ietf:params:xml:ns:domain-1.0
         domain-1.0.xsd\">
         <domain:name>" . $domain . "</domain:name>
         <domain:period unit=\"y\">1</domain:period>
         <domain:registrant>" . $registrant . "</domain:registrant>
         <domain:authInfo>
           <domain:pw>0</domain:pw>
         </domain:authInfo>
       </domain:create>
     </create>
     <clTRID>" . $TXID . "</clTRID>
   </command>
</epp>";

            $now = new DateTime("now", new DateTimeZone('UTC'));
            print $now->format("H:i:s:u") . " Sending $TXID" . PHP_EOL;

            fputs($this->connection, $createDomainXML, strlen($createDomainXML));

            $now = new DateTime("now", new DateTimeZone('UTC'));
            print $now->format("H:i:s:u") . " Sent $TXID" . PHP_EOL;

            $buffer = '';
            $i ++;

            while (strpos($buffer, $TXID) === false) {
                $buffer .= stream_get_contents($this->connection);
            }

            if ((strpos($buffer, 'successfully') !== false) || ($i >= $maxAttempts)) {
                $done = true;
                if (strpos($buffer, 'successfully') !== false) {
                    $now = new DateTime("now", new DateTimeZone('UTC'));
                    print $now->format("H:i:s:u") . " Success with $TXID" . PHP_EOL;
                } else {
                    $now = new DateTime("now", new DateTimeZone('UTC'));
                    print $now->format("H:i:s:u") . " Failed after $i attempts" . PHP_EOL;
                }
            }

            $TXID = 'TX' . $i;
        }
    }

    // Create a domain aggressively
    public function createDomainAggressively($domain, $password, $registrant, $attempts, $delayMS = 0)
    {
        if ($delayMS == 0) {
            for ($j = 0; $j < $attempts; $j ++) {

                $transactionID = $j;

                $createDomainXML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\"
   xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
   xsi:schemaLocation=\"urn:ietf:params:xml:ns:epp-1.0
   epp-1.0.xsd\">
   <command>
     <create>
       <domain:create
         xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\"
         xsi:schemaLocation=\"urn:ietf:params:xml:ns:domain-1.0
         domain-1.0.xsd\">
         <domain:name>" . $domain . "</domain:name>
         <domain:period unit=\"y\">1</domain:period>
         <domain:registrant>" . $registrant . "</domain:registrant>
         <domain:authInfo>
           <domain:pw>0</domain:pw>
         </domain:authInfo>
       </domain:create>
     </create>
     <clTRID>TX" . $transactionID . "</clTRID>
   </command>
</epp>";
                $now = new DateTime("now", new DateTimeZone('UTC'));
                print $now->format("H:i:s:u") . " sending TX$transactionID" . PHP_EOL;
                fputs($this->connection, $createDomainXML, strlen($createDomainXML));
                $now = new DateTime("now", new DateTimeZone('UTC'));
                print $now->format("H:i:s:u") . " sent TX$transactionID" . PHP_EOL;
            }
        } else {
            // Convert milliseconds to microseconds
            $delayMS = $delayMS * 1000;
            for ($j = 0; $j < $attempts; $j ++) {
                $transactionID = $j;
                $createDomainXML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\"
   xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
   xsi:schemaLocation=\"urn:ietf:params:xml:ns:epp-1.0
   epp-1.0.xsd\">
   <command>
     <create>
       <domain:create
         xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\"
         xsi:schemaLocation=\"urn:ietf:params:xml:ns:domain-1.0
         domain-1.0.xsd\">
         <domain:name>" . $domain . "</domain:name>
         <domain:period unit=\"y\">1</domain:period>
         <domain:registrant>" . $registrant . "</domain:registrant>
         <domain:authInfo>
           <domain:pw>$password</domain:pw>
         </domain:authInfo>
       </domain:create>
     </create>
     <clTRID>TX" . $transactionID . "</clTRID>
   </command>
</epp>";

                fputs($this->connection, $createDomainXML, strlen($createDomainXML));

                usleep($delayMS);
            }
        }
        $response = $this->readEPP($this->connection);
        print $response;
        return $response;
    }

    // Create a contact
    public function createContact($contactID, $contactName, $contactStreet, $contactCity, $contactSp, $contactPc, $contactCc, $contactVoice, $contactEmail)
    {
        $createContactXML = '<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0
    epp-1.0.xsd">
    <command>
        <create>
            <contact:create
                xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"
                xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0
                contact-1.0.xsd">
                <contact:id>' . $contactID . '</contact:id>
                <contact:postalInfo type="loc">
                    <contact:name>' . $contactName . '</contact:name>
                    <contact:addr>
                        <contact:street>' . $contactStreet . '</contact:street>
                        <contact:city>' . $contactCity . '</contact:city>
                        <contact:sp>' . $contactSp . '</contact:sp>
                        <contact:pc>' . $contactPc . '</contact:pc>
                        <contact:cc>' . $contactCc . '</contact:cc>
                    </contact:addr>
                </contact:postalInfo>
                <contact:voice>' . $contactVoice . '</contact:voice>
                <contact:email>' . $contactEmail . '</contact:email>
                <contact:authInfo>
                    <contact:pw>NotUsed</contact:pw>
                </contact:authInfo>
            </contact:create>
        </create>
    </command>
</epp>';

        $response = $this->sendEPP($createContactXML);
        if (stripos($response, "Object Exists") !== false) {
            return ("Object Exists");
        } else {
            return ("OK");
        }
    }

    // Check a domain
    public function checkDomain($domain)
    {
        $checkDomainXML = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
    <check>
      <domain:check
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>' . $domain . '</domain:name>
      </domain:check>
    </check>
    <clTRID>ABC-12345</clTRID>
  </command>
</epp>';

        return $this->sendEPP($checkDomainXML);
    }

    public function getDropTime($domain)
    {
        // DEBUG
        // return (new DateTime("+70 seconds", new DateTimeZone('UTC')));
        //
        $checkDomainXML = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
    <check>
      <domain:check
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>' . $domain . '</domain:name>
      </domain:check>
    </check>
    <clTRID>ABC-12345</clTRID>
  </command>
</epp>';

        $response = $this->sendEPP($checkDomainXML, 10);
        // print $response;
        $pattern = '/<domain:reason>drop (.*)<\/domain:reason>/';

        $result = preg_match($pattern, $response, $matches);

        if ($result == 1) {
            $dropTime = str_replace('T', ' ', $matches[1]);
            $dropTime = str_replace('Z', '', $dropTime);
            $dropTime = date_create_from_format('Y-m-d H:i:s', $dropTime, new DateTimeZone('UTC'));
            return ($dropTime);
        } else {
            return (false);
        }
    }

    public function getStatus($domain)
    {
        $checkDomainXML = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
    <check>
      <domain:check
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>' . $domain . '</domain:name>
      </domain:check>
    </check>
    <clTRID>ABC-12345</clTRID>
  </command>
</epp>';

        $response = $this->sendEPP($checkDomainXML);

        $pattern = '/<domain:reason>(.*)<\/domain:reason>/';

        $result = preg_match($pattern, $response, $matches);

        $status = $matches[1];
        return ($status);
    }

    public function keepAlive()
    {
        $keepAliveXML = '<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0
	epp-1.0.xsd">
	<hello/>
</epp>';

        $response = $this->sendEPP($keepAliveXML);

        return (0);
    }

    // Send EPP request
    public function sendEPP($data, $timeout = 3)
    {
        fputs($this->connection, $data, strlen($data));

        $response = $this->readEPP($this->connection, $timeout);

        return $response;
    }

    // Read EPP responses
    public function readEPP($connection, $timeout = 3)
    {
        $buffer = null;
        $time_pre = microtime(true);
        while (! feof($connection)) {
            $buffer .= stream_get_contents($connection, - 1);
            $time_post = microtime(true);
            $end_time = $time_post - $time_pre;

            if ($end_time > $timeout) {
                break;
            }
        }

        return $buffer;
    }
}
?>