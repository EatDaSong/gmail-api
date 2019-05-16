<?php

session_start();
//unset($_SESSION['access_token']);
require __DIR__ . '/vendor/autoload.php';

//CONFIG DE TEST 
//
$search = "";
$utilisateur = 19;
$dbh = new PDO('mysql:host=localhost;dbname=crm-simplon-02', "root", "");
//
//FIN CONFIG DE TEST

//Fonctions
function decodeBody($body) {
    $rawData = $body;
    $sanitizedData = strtr($rawData, '-_', '+/');
    $decodedMessage = base64_decode($sanitizedData);
    if (!$decodedMessage) {
        $decodedMessage = FALSE;
    }
    return $decodedMessage;
}

function getLabels($service)    {
    $labels = array();
    foreach($service->users_labels->listUsersLabels('me')->labels as $label)    {
        $labels = array_merge($labels, array($label->id => $label->name));
    }
    return $labels;
}

function getMessages($service, $search, $label, $nextPageToken = null, $arrayMessagesIds = []) {
    if ($nextPageToken != null)
        $messages = $service->users_messages->listUsersMessages('me', ['pageToken' => $nextPageToken]);
    else
        $messages = $service->users_messages->listUsersMessages('me', ['q' => $search, 'labelIds' => $label]);

    $arrayMerged = (object) array_merge((array) $messages['messages'], (array) $arrayMessagesIds);

    if (isset($messages['nextPageToken']))
        return getMessages($service, $search, $label, $messages['nextPageToken'], $arrayMerged);
    else
        return $arrayMerged;
}

function getBodyMessage($elem) {
    $payload = $elem->getPayload();

    $body = $payload->getBody();
    $FOUND_BODY = decodeBody($body['data']);

    if (!$FOUND_BODY) {
        $parts = $payload->getParts();
        foreach ($parts as $part) {
            if ($part['body']->data != "") {
                $FOUND_BODY = decodeBody($part['body']->data);
                break;
            }
            // Last try: if we didn't find the body in the first parts, 
            // let's loop into the parts of the parts (as @Tholle suggested).
            if ($part['parts'] && !$FOUND_BODY) {
                foreach ($part['parts'] as $p) {
                    // replace 'text/html' by 'text/plain' if you prefer
                    if ($p['mimeType'] === 'text/html' && $p['body']) {
                        $FOUND_BODY = decodeBody($p['body']->data);
                        break;
                    }
                }
            }
            if ($FOUND_BODY) {
                break;
            }
        }
    }
    return $FOUND_BODY;
}

function getSubjectMessage($message) {
    foreach ($message->payload->headers as $header) {
        if ($header->name == "Subject") {
            $subject = $header->value;
            break;
        }
    }
    if(isset($subject) && !empty($subject)) {
        return $subject;
    }   else   {
        return "Pas de sujet";
    }
}

function updateMailBdd($dbh, $service, $utilisateur, $messagesIds = array()) {
    $verifRqt = $dbh->query('SELECT messageId from mails WHERE utilisateur = ' . $utilisateur); //Prochainement email_proprietaire
    $arrayMessageIdTable = $verifRqt->fetchAll(PDO::FETCH_COLUMN, 0);
    $labels = getLabels($service);
    foreach ($messagesIds as $messageId) {
        if (!in_array($messageId->id, $arrayMessageIdTable)) {
            $message = $service->users_messages->get('me', $messageId->id, ['format' => 'full']);
            $stringLabels = "";
            foreach($message->labelIds as $label)   {
                $stringLabels .= '{'. $labels[$label] .'}';
            }
            //$query = $dbh->query('INSERT INTO `mails`(`messageId`, `label`, `etat`, `infos`, `email`, `date`, `utilisateur`, `sujet`, `contenu`, `pj`, `threadId`) VALUES ("'.$message->id.'","'.$stringLabels.'","","","","2019-01-01 00:00:00",'.$utilisateur.',"'.getSubjectMessage($message).'","'.getBodyMessage($message).'","","'.$message->threadId.'")');
            $prepare = $dbh->prepare('INSERT INTO `mails` (`messageId`, `label`, `date`, `utilisateur`, `sujet`, `contenu`, `threadId`) VALUES (:messageId, :label, :date, :utilisateur, :sujet, :contenu, :threadId)');
            $data = [
                'messageId' => $message->id,
                'label' => $stringLabels,
                'date' => "2019-01-01 00:00:00",
                'utilisateur' => $utilisateur,
                'sujet' => getSubjectMessage($message),
                'contenu' => getBodyMessage($message),
                'threadId' => $message->threadId
            ];
            $prepare->execute($data);
            if(!$prepare) {
                var_dump($dbh->errorInfo());
            }   else    {
                echo "Enregistrement " . $messageId->id . "</br>";
            }
        }
    }
}

$client = new Google_Client();

/* CONFIG, FUTUR CONSTRUCTOR */
$client->setClientId('61902259420-fkgarj0kkn5l254ggl9jfl3jqn9i1s0p.apps.googleusercontent.com'); //OAuth
$client->setClientSecret('7RrYBuup1ejAoB9g3cDGIoXO'); //OAuth
$client->setRedirectUri('http://localhost/sserenity/gmail2.php'); //Redirection ?
$client->addScope('https://mail.google.com/'); //SCOPE, mail.google.com pour accès total au compte cf https://developers.google.com/gmail/api/auth/scopes

//Création services gmail
$service = new Google_Service_Gmail($client);

if (isset($_REQUEST['logout'])) {
    unset($_SESSION['access_token']);
}

//Vérification si nous avons une autorisation par token
if (isset($_GET['code'])) {
    $client->authenticate($_GET['code']);
    $_SESSION['access_token'] = $client->getAccessToken();
    $url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    header('location: ' . filter_var($url, FILTER_VALIDATE_URL));
}

// Check if we have an access token in the session
if (isset($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);
    echo "Vous êtes identifié <br><br>";
} else {
    $loginUrl = $client->createAuthUrl();
    echo 'Cliquez <a href="' . $loginUrl . '">ICI</a> pour vous identifier';
}

//Vérification si nous avons un access_token près pour l'appel d'api
try {
    if (isset($_SESSION['access_token']) && $client->getAccessToken()) {
        updateMailBdd($dbh, $service, $utilisateur, getMessages($service, $search, "INBOX"));
    }
} catch (Google_Auth_Exception $e) {
    echo $e;
    echo 'Votre jeton d\'accès semble avoir éxpiré. Cliquez <a href="' . $loginUrl . '">ICI</a> pour vous connecter';
}