<?
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

global $USER;
use Firstbit\Helper;
use Firstbit\RabbitQuery;
  
$request = Bitrix\Main\Context::getCurrent()->getRequest();
$dataFromFront = $request->getJsonList()->toArray(); 

$capcha = '';
if(isset($dataFromFront["token"])) {
	$capcha = (string) htmlspecialcharsEx($dataFromFront["token"]);
}

/**
 * ОПИСАНИЕ ОШИБОК (строка ошибки - это класс элемента в DOM с описанием ошибки который нужно будет показать пользователю)
 * v-error-message--email-incorrectly - если не корректен email
 * v-error-message--registered - пользователь с присланным email уже зарегистрирован
 * v-error-message--mismatch - пароль и повторение пароля не совпадают
 * v-error-message--invalid-password - если длинна пароля менее 6 символов
 * v-error-message--user-creation-error - ошибка создания пользователя
 */
 

$secretKey = 'key';

$url = 'https://www.google.com/recaptcha/api/siteverify?secret='.$secretKey.'&response='.$capcha;

 
$response = file_get_contents($url);

$responseKeys = json_decode($response, true);

header('Content-type: application/json');

// var_dump($responseKeys);
//  die();

if($responseKeys["success"] && $responseKeys["score"] >= 0.5){
    //echo json_encode(['success' => 'true', 'om_score' => $responseKeys['score'], 'token' =>$dataFromFront["token"]]);

    $neofitEmail = ''; 
    if(!empty($dataFromFront["EMAIL"])) {
        $neofitEmail = htmlspecialcharsEx( (string) $dataFromFront["EMAIL"]);
    } else {
        echo 'v-error-message--email-incorrectly';
        die();
    }
 
    $neofitPassword = '';
    if(!empty($dataFromFront["PASSWORD"])) {
        $neofitPassword =  htmlspecialcharsEx( (string) $dataFromFront["PASSWORD"]);
    } else {
        echo 'v-error-message--invalid-password';
        die();
    }
    
    $neofitConfirmPassword = '';
    if(!empty($dataFromFront["CONFIRM_PASSWORD"])) {
        $neofitConfirmPassword = htmlspecialcharsEx( (string) $dataFromFront["CONFIRM_PASSWORD"]);
    }
    
 
    // Проверка сессии
    if (empty($dataFromFront['sessid']) || $dataFromFront['sessid'] !== bitrix_sessid()) {
        die();
    }

    //Проверка email
    if(!check_email($neofitEmail)) {
        echo 'v-error-message--email-incorrectly';
        die();
    }

    // Проверка существование пользователя
    $obUser = new CUser(); 
 
    if ($obUser::GetByLogin($neofitEmail)->Fetch()) { 
        echo 'v-error-message--registered';
        die();
    }
    //Проверка совпадения пароля и повторения пароля
    if($neofitPassword !== $neofitConfirmPassword) {
        echo 'v-error-message--mismatch';
        die();
    }
    // Проверка длинны пароля
    if(strlen($neofitPassword) < 6) {
        echo 'v-error-message--invalid-password';
        die();
    }
    
    // Создание пользователя 
		$group = [Helper::getUserGroupByCode("REGISTERED_USERS"), Helper::getUserGroupByCode("DISTANCE_LEARNING")]; 
		$arUserFields = [
			'EMAIL' => $neofitEmail,
			'LOGIN' => $neofitEmail,
			'GROUP_ID' => $group, 
			"PASSWORD" => $neofitPassword,
			"CONFIRM_PASSWORD" => $neofitPassword,
			'UF_SOURCE' => 'LKS',
			'LID' => SITE_ID
		];

		$newUserId = $obUser->Add( (array) $arUserFields);
 
        if($newUserId) {
            // Отправка данных пользователя ему на почту
            CEvent::Send("NEW_USER", SITE_ID, ['LOGIN' => $neofitEmail, 'PASSWORD' => $neofitPassword]);
            
            // Получение полей пользователя
            $arUserPackage = Helper::prepareUserArray((int) $newUserId);

            // Отправляю данные пользователя в 1С
            if (!empty($arUserPackage)) 
            {
                $RabbitQuery = new RabbitQuery();
                $RabbitQuery->add("sendHumans", $arUserPackage, ['userId' => $newUserId, 'email' => $arParams['email']]);
            }  
            echo 'OK';
            die(); 
        }
        else
        {
            echo 'v-error-message--user-creation-error';
        }
        
}
else
{
    // echo json_encode(['success' => 'false', 'om_score' => $responseKeys['score'], 'token' =>$dataFromFront["token"]]);
    echo 'Вы распознаны как бот';
}
