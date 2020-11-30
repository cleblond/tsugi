<?php
// In the top frame, we use cookies for session.
if (!defined('COOKIE_SESSION')) define('COOKIE_SESSION', true);
require_once("../../config.php");

use \Tsugi\Util\U;
use \Tsugi\Util\Net;
use \Tsugi\Core\LTIX;

$openid_configuration = U::get($_REQUEST, 'openid_configuration');
$registration_token = U::get($_REQUEST, 'registration_token');
$tsugi_key = U::get($_REQUEST, 'tsugi_key');

session_start();

$LTI = U::get($_SESSION, 'lti');

$display_name = U::get($LTI, 'displayname');
$user_id = U::get($LTI, 'user_id');

$OUTPUT->header();
$OUTPUT->bodyStart();

if ( ! $user_id ) {
?>
<p>You are not logged in.
</p>
<p>
<a href="<?= $CFG->apphome ?>" target="_blank"><?= $CFG->apphome ?></a>
</p>
<p>
Open this in a new tab, login, and come back to this tab and
re-check your login status.
</p>
<p>
<form>
<input type="hidden" name="openid_configuration" value="<?= htmlentities($openid_configuration) ?>">
<input type="hidden" name="registration_token" value="<?= htmlentities($registration_token) ?>">
<input type="hidden" name="tsugi_key" value="<?= htmlentities($tsugi_key) ?>">
<input type="submit" name="Re-Check Login Status" value="Re-Check Login Status">
</form>
<?php
    $OUTPUT->footer();
    return;
}

$response = Net::doGet($openid_configuration );
$code = Net::getLastHttpResponse();
if ( ! $response || strlen($response) < 1 ) {
    echo("<pre>\n");
    echo("Unable to retrieve:\n".htmlentities($openid_configuration)."\n");
    echo("Error code:".htmlentities($code)."\n");
    echo("</pre>\n");
    return;
}

$platform_configuration = json_decode($response);
if ( ! $platform_configuration || ! is_object($platform_configuration) ) {
    echo("<pre>\n");
    echo("Unable to parse JSON retrieved from:\n".htmlentities($openid_configuration)."\n\n");
    echo(htmlentities($response));
    echo("</pre>\n");
    return;
}

// Parse the response and make sure we have the required values.
try {
  $issuer = $platform_configuration->issuer;
  $authorization_endpoint = $platform_configuration->authorization_endpoint;
  $token_endpoint = $platform_configuration->token_endpoint;
  $jwks_uri = $platform_configuration->jwks_uri;
  $registration_endpoint = $platform_configuration->registration_endpoint;
} catch (Exception $e) {
    echo("<pre>\n");
    echo 'Missing required value: ',  htmlentities($e->getMessage()), "\n";
    echo("</pre>\n");
    return;
}

\Tsugi\Core\LTIX::getConnection();

// Lets retrieve our key entry if it belongs to us
$row = $PDOX->rowDie(
    "SELECT key_title, key_key, issuer_key, issuer_client,
        lti13_oidc_auth, lti13_keyset_url, lti13_token_url
    FROM {$CFG->dbprefix}lti_KEY AS K
        LEFT JOIN {$CFG->dbprefix}lti_issuer AS I ON
            K.issuer_id = I.issuer_id
        WHERE key_id = :KID AND K.user_id = :UID",
    array(":KID" => $tsugi_key, ":UID" => $user_id)
);

if ( ! $row ) {
    echo("<pre>\n");
    echo "Could not load your key\n";
    echo("</pre>\n");
    return;
}

echo("<pre>\n");

print_r($row);

// See the end of the file for some documentation references
$json = new \stdClass();
$tool = new \stdClass();

$json->application_type = "web";
$json->response_types = array("id_token");
$json->grant_types = array("implicit", "client_credentials");
$json->initiate_login_uri = $CFG->wwwroot . '/lti/oidc_login/' . urlencode($tsugi_key);
$json->redirect_uris = array($CFG->wwwroot . '/lti/oidc_launch');
if ( isset($CFG->servicename) && $CFG->servicename ) {
    $json->client_name = $CFG->servicename;
}
$json->jwks_uri = $CFG->wwwroot . '/lti/keyset/' . urlencode($tsugi_key);
if ( isset($CFG->privacy_url) && $CFG->privacy_url ) {
    $json->policy_uri = $CFG->privacy_url;
}
if ( isset($CFG->sla_url) && $CFG->sla_url ) {
    $json->tos_uri = $CFG->sla_url;
}
$json->token_endpoint_auth_method = "private_key_jwt";

if ( isset($CFG->owneremail) && $CFG->owneremail ) {
    $json->contacts = array($CFG->owneremail);
    $contact = new \stdClass();
    $contact->email = $CFG->owneremail;
    if ( isset($CFG->ownername) && $CFG->ownername ) $contact->display_name = $CFG->ownername;
    $tool->better_contacts = array($contact);
}

$tool->product_family_code = "tsugi.org";
$tool->target_link_uri = $CFG->wwwroot . '/lti/store/';

$pieces = parse_url($CFG->apphome);
if ( U::get($pieces, 'host') ) $tool->domain = U::get($pieces, 'host');

if ( isset($CFG->servicedesc) && $CFG->servicedesc ) {
    $tool->description = $CFG->servicedesc;
}

$tool->claims = array( "iss", "sub", "name", "given_name", "family_name" );

// TODO: Issue #53 - Define placements...
$tool->messages = array(
    array(
        "type" => "LtiDeepLinkingRequest",
        "label" => $CFG->servicedesc,
        "target_link_uri" => $CFG->wwwroot . '/lti/store',
    ),
    array(
        "type" => "LtiDeepLinkingRequest",
        "label" => $CFG->servicedesc,
        "target_link_uri" => __("Import from") . " ". $CFG->wwwroot . '/cc/export',
        "placements" => array( "migration_selection")
    ),
    array(
        "type" => "LtiDeepLinkingRequest",
        "label" => $CFG->servicedesc,
        "target_link_uri" => $CFG->wwwroot . '/lti/store?type=link_selection',
        "placements" => array( "link_selection")
    ),
    array(
        "type" => "LtiDeepLinkingRequest",
        "label" => $CFG->servicedesc,
        "target_link_uri" => $CFG->wwwroot . '/lti/store?type=editor_button',
        "placements" => array( "editor_button")
    ),
    array(
        "type" => "LtiDeepLinkingRequest",
        "label" => $CFG->servicedesc,
        "target_link_uri" => $CFG->wwwroot . '/lti/store?type=assignment_selection',
        "placements" => array( "assignment_selection")
    ),
    array(
        "type" => "LtiEmergentPrivacyRequest",
        "label" => $CFG->servicedesc,
        "target_link_uri" => $CFG->wwwroot,
    ),
);

$json->{"https://purl.imsglobal.org/spec/lti-tool-configuration"} = $tool;

echo("\n");
echo(json_encode($json, JSON_PRETTY_PRINT));

echo("\n</pre>\n");

/*

POST /connect/register HTTP/1.1
Content-Type: application/json
Accept: application/json
Host: server.example.com
Authorization: Bearer eyJhbGciOiJSUzI1NiJ9.eyJ .

{
    "application_type": "web",
    "response_types": ["id_token"],
    "grant_types": ["implict", "client_credentials"],
    "initiate_login_uri": "https://client.example.org/lti",
    "redirect_uris":
      ["https://client.example.org/callback",
       "https://client.example.org/callback2"],
    "client_name": "Virtual Garden",
    "client_name#ja": "バーチャルガーデン",
    "jwks_uri": "https://client.example.org/.well-known/jwks.json",
    "logo_uri": "https://client.example.org/logo.png",
    "policy_uri": "https://client.example.org/privacy",
    "policy_uri#ja": "https://client.example.org/privacy?lang=ja",
    "tos_uri": "https://client.example.org/tos",
    "tos_uri#ja": "https://client.example.org/tos?lang=ja",
    "token_endpoint_auth_method": "private_key_jwt",
    "contacts": ["ve7jtb@example.org", "mary@example.org"],
    "scope": "https://purl.imsglobal.org/spec/lti-ags/scope/score https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly",
    "https://purl.imsglobal.org/spec/lti-tool-configuration": {
        "domain": "client.example.org",
        "description": "Learn Botany by tending to your little (virtual) garden.",
        "description#ja": "小さな（仮想）庭に行くことで植物学を学びましょう。",
        "target_link_uri": "https://client.example.org/lti",
        "custom_parameters": {
            "context_history": "$Context.id.history"
        },
        "claims": ["iss", "sub", "name", "given_name", "family_name"],
        "messages": [
            {
                "type": "LtiDeepLinkingRequest",
                "target_link_uri": "https://client.example.org/lti/dl",
                "label": "Add a virtual garden",
                "label#ja": "バーチャルガーデンを追加する",
            }
        ]
    }
}
 */