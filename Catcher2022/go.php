<?php
//Just gimme some kind of sign...
$workingDir = 'c:\\temp\\';
$configFile='config.ini';
$config=parse_ini_file($configFile);
$tag=$config['tag'];
$password=$config['password'];
$registrant=$config['registrant'];

$nullDate = date_create("1970-01-01 00:00:00", timezone_open("UTC"));
// Send the domain create request this number of milliseconds before drop time
$msAdvance = 120;
// Number of registration attempts to make per domain
$registrationAttempts = 10;
$epp = new Epp();

$epp->connect();
$epp->login($tag, $password);
$epp->createContact();

class domain
{

    public string $name;

    public DateTime $dropTime;
}

$targetDomains = file($workingDir . 'domains.txt');

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

$epp->logout();

$currentTarget = 0;
$totalTargets = sizeof($domains);

while ($currentTarget < $totalTargets) {
    if (! (($domains[$currentTarget]->dropTime) == $nullDate)) {
        print "Next target is " . $domains[$currentTarget]->name . PHP_EOL;
        $now = new DateTime("now", new DateTimeZone('UTC'));
        $delay = $domains[$currentTarget]->dropTime->getTimestamp() - $now->getTimestamp();
        $delay = $delay - 60;
        print "Sleeping for $delay seconds (one minute before " . $domains[$currentTarget]->dropTime->format('Y-m-d H:i:s') . ")" . PHP_EOL;
        sleep($delay);
        print "Connecting to EPP" . PHP_EOL;
        $epp->connect();
        print "Logging on to EPP" . PHP_EOL;
        $epp->login($tag, $password);
        // Drop time in milliseconds
        $msDropTime = dateTimeToMilliseconds($domains[$currentTarget]->dropTime);
        // $msDropTime=(int) (microtime(true) * 1000);
        print "Waiting for the precise time" . PHP_EOL;
        $attemptMade = false;
        while (! $attemptMade) {
            // If current time in milliseconds is greater than or equalal to droptime minus our offset
            if ((int) (microtime(true) * 1000) >= ($msDropTime - $msAdvance)) {
                /*
                 * for ($i = 0; $i < $registrationAttempts; $i ++) {
                 * $epp->createDomain($domains[$currentTarget]->name, $password, $registrant, $i);
                 * }
                 */
                print $epp->createDomainAggressively($domains[$currentTarget]->name, $password, $registrant, 200, 0);
                print "$registrationAttempts attempts to register " . $domains[$currentTarget]->name . " have been made" . PHP_EOL;
                $attemptMade = true;
            } else {
                // Sleep for 1 millisecond
                usleep(1000);
            }
        }
        $epp->logout();
    } else {
        print "Skipping " . $domains[$currentTarget]->name . " due to invalid drop date" . PHP_EOL;
    }
    $currentTarget ++;
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

print_r($domains);

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
            exit("Message: Script failed, likely cause is the recent addition of the IP address to the Nominet test bed due to the large amount of people currently setting up. Please try running the script in 60 minutes time again.\r\n");
        }

        stream_set_blocking($this->connection, (int) false);
        stream_set_write_buffer($this->connection, 0);

        $this->readEPP($this->connection);
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

        return $this->sendEPP($loginXML, "Command completed successfully");
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

        return $this->sendEPP($logoutXML, "Command completed successfully");
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
                fputs($this->connection, $createDomainXML, strlen($createDomainXML));
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

        return $response;
    }

    // Create a contact
    public function createContact()
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
                <contact:id>HENRI</contact:id>
                <contact:postalInfo type="loc">
                    <contact:name>Henri Gilbert</contact:name>
                    <contact:addr>
                        <contact:street>38 Carlin Gate</contact:street>            
                        <contact:city>Blackpool</contact:city>
                        <contact:sp>England</contact:sp>
                        <contact:pc>FY2 9QU</contact:pc>
                        <contact:cc>GB</contact:cc>
                    </contact:addr>
                </contact:postalInfo>
                <contact:voice>+44.7990708422</contact:voice>
                <contact:email>henri_gilbert@hotmail.com</contact:email>
                <contact:authInfo>
                    <contact:pw>zRsW26VemKUh4D</contact:pw>
                </contact:authInfo>
            </contact:create>
        </create>
    </command>
</epp>';

        return $this->sendEPP($createContactXML, "Command completed successfully");
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

    // Send EPP request
    public function sendEPP($data)
    {
        fputs($this->connection, $data, strlen($data));

        $response = $this->readEPP($this->connection);

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
?>;