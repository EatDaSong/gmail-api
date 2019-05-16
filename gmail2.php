<?php

session_start();
//unset($_SESSION['access_token']);
require __DIR__ . '/vendor/autoload.php';

//CONFIG DE TEST 
//
$search = "";
$utilisateur = 19;
$dbh = new PDO('mysql:host=localhost;dbname=crm-simplon-02', "root", "");
$dbh -> exec("SET CHARACTER SET utf8");

//
//FIN CONFIG DE TEST
//Fonctions
//Aussi simple que possible
function decodeBody($body) {
    $decodedMessage = base64_decode(cleanBase64($body));
    if (!$decodedMessage) {
        $decodedMessage = FALSE;
    }
    return $decodedMessage;
}

function cleanBase64($base64) {
    $sanitizeData = strtr($base64, '-', '+');
    $sanitizeData = strtr($sanitizeData, '_', '/');
    return $sanitizeData;
}

//On récup les labels IDS et leurs correspondance afin de retourner un tableau
function getLabels($service) {
    $labels = array();
    foreach ($service->users_labels->listUsersLabels('me')->labels as $label) {
        $labels = array_merge($labels, array($label->id => $label->name));
    }
    return $labels;
}

//Fonction récursive qui s'appel jusqu'à ne plus avoir de "nextPageToken" affin de construire le tableau des messagesIds
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
            //Si on ne trouve pas le body on boucle dans les parties des parties
            if ($part['parts'] && !$FOUND_BODY) {
                foreach ($part['parts'] as $p) {
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

//On récupère le sujet, optimisable car ne trouve pas le sujet à tous les coups. s'inspirer de getBody?
function getSubjectMessage($message) {
    foreach ($message->payload->headers as $header) {
        if ($header->name == "Subject") {
            $subject = $header->value;
            break;
        }
    }
    if (isset($subject) && !empty($subject)) {
        return $subject;
    } else {
        return "Pas de sujet";
    }
}

function getPjMessage($message) {
    //$arrayPj = array();
    $stringPj = "";
    foreach ($message->payload->parts as $part) {
        if (isset($part->body->attachmentId)) {
            $stringPj .= "{" . $part->filename . "}";
            //Récuperation de pièces jointe à terminer
            /* var_dump($part);
              $attachment = $service->users_messages_attachments->get('me', $message->id, $part->body->attachmentId);
              var_dump($attachment);
              array_push($arrayPj, $attachment['data']);
              echo '<audio controls src="data:audio/x-wav;base64,'. cleanBase64($attachment['data']).'" />';
              $test = fopen('test.txt', 'w+');
              fwrite($test, decodeBody($attachment['data']));
              fclose($test); */
        }
    }
    return $stringPj;
}

//Récupération état
function getEtatMessage($message) {
    foreach ($message->payload->headers as $header) {
        if ($header->name == "Delivered-To") {
            $etat = "reçu";
        }
    }
    if(!isset($etat))   {
        $etat = "envoyé";
    }
    return $etat;
}

//Obtention de la date
function getDateMessage($message)   {
    foreach($message->payload->headers as $header) {
        if($header->name == "Date") {
            $date = date('Y-m-d H:i:s', strtotime($header->value));
            break;
        }
    }
    return $date;
}

//Récupération interlocuteur
function getInterlocuteurs($message) {
    foreach ($message->payload->headers as $header) {
        if ($header->name == "From") {
            $from = $header->value;
        }
        if ($header->name == "To") {
            $to = $header->value;
        }
    }
    if(!isset($to)) {
        $to = "Pas séléctionné";
    }
    return array('from' => cleanEmail($from), 'to' => cleanEmail($to));
}

//On ne garde que l'email
function cleanEmail($interlocuteur) {
    if (strpos($interlocuteur, '<') != false && strpos($interlocuteur, '>') != false) {
        $interlocuteur = substr($interlocuteur, strpos($interlocuteur, '<') + 1, strpos($interlocuteur, '>') - strlen($interlocuteur));
    }
    
    return $interlocuteur;
}

//Mise en base de donnée des nouveaux mails
function updateMailBdd($dbh, $service, $utilisateur, $messagesIds = array()) {
    //Récupération des IDs déjà en BDD
    $verifRqt = $dbh->query('SELECT messageId from mails WHERE utilisateur = ' . $utilisateur); //Prochainement email_proprietaire
    $arrayMessageIdTable = $verifRqt->fetchAll(PDO::FETCH_COLUMN, 0);

    //Récupération email propriétaire
    $proprietaire = $service->users->getProfile('me')->emailAddress;

    //Récupération des labels
    $labels = getLabels($service);

    //Boucle sur les messagesIds fourni par l'api gmail
    foreach ($messagesIds as $messageId) {
        if (!in_array($messageId->id, $arrayMessageIdTable)) {
            //Get message
            $message = $service->users_messages->get('me', $messageId->id, ['format' => 'full']);

            //Construction des labels
            $stringLabels = "";
            foreach ($message->labelIds as $label) {
                $stringLabels .= '{' . $labels[$label] . '}';
            }

            //récup état pour interlocuteur et insertion
            $interlocuteurs = getInterlocuteurs($message);
            
            //Insertion en bdd        
            $prepare = $dbh->prepare('INSERT INTO `mails` (`messageId`, `label`, `etat`, `proprietaire`, `from`, `to`, `date`, `utilisateur`, `sujet`, `contenu`, `pj`, `threadId`) VALUES (:messageId, :label, :etat, :proprietaire, :from, :to, :date, :utilisateur, :sujet, :contenu, :pj, :threadId)');
            $data = [
                'messageId' => $message->id,
                'label' => $stringLabels,
                'etat' => getEtatMessage($message),
                'proprietaire' => $proprietaire,
                'from' => $interlocuteurs['from'],
                'to' => $interlocuteurs['to'],
                'date' => getDateMessage($message),
                'utilisateur' => $utilisateur,
                'sujet' => getSubjectMessage($message),
                'contenu' => getBodyMessage($message),
                'pj' => getPjMessage($message),
                'threadId' => $message->threadId
            ];
            $prepare->execute($data);

            //Verification d'érreur
            if (!$prepare) {
                var_dump($dbh->errorInfo());
            } else {
                //Log
                echo "Enregistrement " . $messageId->id . "</br>";
            }
            //break;
        }
    }
}

$client = new Google_Client();

/* CONFIG, FUTUR CONSTRUCTOR */
$client->setClientId('131904409282-8e87rpp0jgrb34astci17v4n7pocehrd.apps.googleusercontent.com'); //OAuth
$client->setClientSecret('KjXIqzM4Fm5g2_zFgcK-c0rq'); //OAuth
$client->setRedirectUri('http://localhost/gmail-api/gmail2.php'); //Redirection ?
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
        updateMailBdd($dbh, $service, $utilisateur, getMessages($service, $search, array()));
    }
} catch (Google_Auth_Exception $e) {
    echo $e;
    echo 'Votre jeton d\'accès semble avoir éxpiré. Cliquez <a href="' . $loginUrl . '">ICI</a> pour vous connecter';
}