<?php
// определяем кодировку
header('Content-type: text/html; charset=utf-8');
// Создаем объект бота
$bot = new Bot();
// Обрабатываем пришедшие данные
$bot->init('php://input');

/**
 * Class Bot
 */
class Bot
{
    // <bot_token> - созданный токен для нашего бота от @BotFather
    private $botToken = "";
    // адрес для запросов к API Telegram
    private $apiUrl = "https://api.telegram.org/bot";


    public function init($data)
    {
        $host=''; // имя хоста (где лежит бд)
        $user=''; // имя пользователя
        $pswd=''; // пароль
        $database=''; // имя базы данных

        $ErrorQuery = 0;
        $errorConnect = 0;
        $errorSelect = 0;
        $dbh = mysql_connect($host, $user, $pswd) or $errorConnect = 1;
        mysql_set_charset('utf8',$dbh);
        if(!$errorConnect) {
            mysql_select_db($database) or $errorSelect;
        }

        // создаем массив из пришедших данных от API Telegram
        $arrData = $this->getData($data);

        // лог
        // $this->setFileLog($arrData);

        //Здесь мы создадим стандартные настройки
        $arrSettings = array(
            array("name" => "count_row", "param" =>3, "id" => -1)
        );


        //Здесь мы получаем ид пользователя, требуется проверить его в бд. Есть он там или нет?
        if (array_key_exists('message', $arrData)) {
            $chat_id = $arrData['message']['chat']['id'];
            $message = $arrData['message']['text'];

            $first_name = $arrData['message']['chat']['first_name'];
            $last_name = $arrData['message']['chat']['last_name'];
            $username = $arrData['message']['chat']['username'];


        } elseif (array_key_exists('callback_query', $arrData)) {
            $chat_id = $arrData['callback_query']['message']['chat']['id'];
            $message = $arrData['callback_query']['data'];

            $first_name = $arrData['callback_query']['message']['chat']['first_name'];
            $last_name = $arrData['callback_query']['message']['chat']['last_name'];
            $username = $arrData['callback_query']['message']['chat']['username'];
        }

        if($chat_id <= 0 || $chat_id == NULL) //Защита от плодения пустых пользователей (При запуске скрипта через браузер для проверок)
            return;

        //Проверяем пользователя, что он существует. И что он живой ещё.
        $users_id = -1;
        $users_time_request = -1;
        $users_timestamp = -1;
        $users_alive = -1;
        $users_admin = -1;

        $query = "SELECT * FROM sp_users WHERE users_telegram_id=$chat_id;";

        $selectUsers = mysql_query($query);
        $rowUsers = mysql_fetch_array($selectUsers);
        mysql_free_result($selectUsers);
        $iTimestamp = time();

        if($rowUsers) {
            $users_id = $rowUsers['users_id'];
            $users_time_request = $rowUsers['users_time_request'];
            $users_timestamp = $rowUsers['users_timestamp'];
            $users_alive = $rowUsers['users_alive'];
            $users_admin = $rowUsers['users_admin'];


            $query = "UPDATE sp_users SET users_time_request='$iTimestamp', users_timestamp='$iTimestamp',users_username='$username',users_lastname='$last_name',users_firstname='$first_name' WHERE users_id='$users_id';";
            mysql_query($query);

        } else { //Пользователь не найден? добавляем его!
            $query = "INSERT INTO sp_users (users_username, users_lastname, users_firstname,users_telegram_id,users_time_request, users_timestamp, users_alive) values ('$username','$last_name','$first_name','$chat_id', '$iTimestamp','$iTimestamp', '1');";
            mysql_query($query);
            $users_id = mysql_insert_id();
        }

        //Здесь проверяем ожидаем ли мы какого либо дейстия.
        $query = "SELECT * FROM sp_command WHERE command_users_id='$users_id';";
        $resInfo = mysql_query($query) or $ErrorQueryCommand = 1;

        //Это нужно для проверки. Таким образом будет реализовано перемещение категорий из одного места в другое.
        $command_id = -1;
        $command_text = "none";
        $command_message_id = -1;
        $command_int_parameter = -1;
        $command_str_parameter = "";


        if(!$ErrorQueryCommand) {
            $rowSelect = mysql_fetch_array($resInfo);
            if($rowSelect) {
                $command_id = $rowSelect['command_id'];
                $command_text = $rowSelect['command_text'];
                $command_message_id = $rowSelect['command_message_id'];
                $command_int_parameter = $rowSelect['command_int_parameter'];
                $command_str_parameter = $rowSelect['command_str_parameter'];

                if($arrData['message']['message_id']-1 > 0) {
                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'message_id' => $arrData['message']['message_id']-1
                    );

                    $this->requestToTelegram($dataSend, "deleteMessage");
                }
                if(preg_match('/^input_search_product/', $command_text)) { //Для админского поиска сужения товара не совмещённого в категорию.


                    // $this->setFileLog($arrData);
                    $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                    mysql_query($query);

                    if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {


                        $message = "main";
                    } else {

                        $search_text = trim($message);

                        $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                        $iCount = count($strDel);
                        for($i=0;$i<$iCount;$i++) {
                            $search_text = str_replace($strDel[$i],' ',$search_text);
                        }
                        $search_text = trim($search_text);

                        $arrParamSearch = explode(" ", $search_text);

                        $iCount = count($arrParamSearch);
                        for($i=0;$i<$iCount;$i++) {
                            $arrParamSearch[$i] = trim($arrParamSearch[$i]);
                        }

                        $arrParamSearch = array_unique($arrParamSearch); //Удаляем повторы на всякий случай.


                        $strText = "";
                        $iCount = count($arrParamSearch);
                        for($i=0;$i<$iCount;$i++) {
                            $strText = $strText . " " . $arrParamSearch[$i];
                        }


                        $query = "INSERT INTO sp_search (search_user_id,search_timestamp, search_text, search_type) values ('$users_id', '$iTimestamp','$strText','product_search');";
                        mysql_query($query);

                        // $message = "menu";
                        $message = "uncategorized_products 0 0 $command_str_parameter";
                    }
                } else
                    if(preg_match('/^input_search/', $command_text)) {


                        // $this->setFileLog($arrData);
                        $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                        mysql_query($query);

                        if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {


                            $message = "main";
                        } else {

                            $search_text = trim($message);

                            $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                            $iCount = count($strDel);
                            for($i=0;$i<$iCount;$i++) {
                                $search_text = str_replace($strDel[$i],' ',$search_text);
                            }
                            $search_text = trim($search_text);

                            $arrParamSearch = explode(" ", $search_text);

                            $iCount = count($arrParamSearch);
                            for($i=0;$i<$iCount;$i++) {
                                $arrParamSearch[$i] = trim($arrParamSearch[$i]);
                            }

                            $arrParamSearch = array_unique($arrParamSearch); //Удаляем повторы на всякий случай.


                            $strText = "";
                            $iCount = count($arrParamSearch);
                            for($i=0;$i<$iCount;$i++) {
                                $strText = $strText . " " . $arrParamSearch[$i];
                            }


                            $query = "INSERT INTO sp_search (search_user_id,search_timestamp, search_text, search_type) values ('$users_id', '$iTimestamp','$strText','quick_search');";
                            mysql_query($query);

                            // $message = "menu";
                            $message = "quick_search 0 0 -1 -1";
                        }
                    } else
                        if(preg_match('/^input_review/', $command_text)) {


                            // $this->setFileLog($arrData);
                            $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                            mysql_query($query);

                            if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {
                                $message = "main";
                            } else {

                                $strCommText = trim($message);

                                $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                                $iCount = count($strDel);
                                for($i=0;$i<$iCount;$i++) {
                                    $strCommText = str_replace($strDel[$i],'',$strCommText);
                                }
                                $strCommText = trim($strCommText);


                                $query = "INSERT INTO sp_comment (comment_users_id,comment_timestamp, comment_text,comment_category_id) values ('$users_id', '$iTimestamp','$strCommText','$command_int_parameter');";
                                mysql_query($query);


                                $dataSend = array(
                                    'text' => "Спасибо за Ваш отзыв \"<b>" . $strCommText . "</b>\" Мы обязательно примем его во внимание!",
                                    'chat_id' => $chat_id,
                                    'parse_mode' => 'html'
                                );

                                $this->requestToTelegram($dataSend, "sendMessage");


                                if(preg_match('/^my_product/', $command_str_parameter) )
                                    $message = "product_info $command_int_parameter 0 $command_str_parameter";
                                else if(preg_match('/^quick_search/', $command_str_parameter) )
                                    $message = "product_info $command_int_parameter 0 $command_str_parameter";
                                else
                                    $message = "product_info $command_int_parameter 0 none";
                            }
                        } else
                            if(preg_match('/^input_category/', $command_text)) {

                                $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                                mysql_query($query);

                                if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {
                                    $message = "main";
                                } else {

                                    $strCategoryName = trim($message);

                                    $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                                    $iCount = count($strDel);
                                    for($i=0;$i<$iCount;$i++) {
                                        $strCategoryName = str_replace($strDel[$i],'',$strCategoryName);
                                    }
                                    $strCategoryName = trim($strCategoryName);


                                    $category_name = "корень";
                                    $query = "";
                                    if($command_int_parameter <= 0 || $command_int_parameter == NULL) { //Если мы в главном меню
                                        $command_int_parameter = -1;
                                        $query = "INSERT INTO sp_category (category_name,category_parent) values ('$strCategoryName',NULL);";
                                    } else {
                                        $query = "SELECT * FROM sp_category WHERE category_id=$command_int_parameter;";

                                        $resInfo = mysql_query($query);
                                        $rowProduct = mysql_fetch_array($resInfo);
                                        mysql_free_result($resInfo);

                                        $category_name = $rowProduct['category_name'];
                                        $query = "INSERT INTO sp_category (category_name,category_parent) values ('$strCategoryName','$command_int_parameter');";

                                    }



                                    mysql_query($query);


                                    $dataSend = array(
                                        'text' => "Вы добавили \"<b>" . $strCategoryName . "</b>\" в категорию $category_name.",
                                        'chat_id' => $chat_id,
                                        'parse_mode' => 'html'
                                    );

                                    $this->requestToTelegram($dataSend, "sendMessage");

                                    $this->setFileLog("Пользователь $users_id (админ $users_admin) $first_name $last_name $username добавил категорию в подкатегорию id $command_int_parameter '$category_name' с именем '$strCategoryName'");


                                    $message = "all_product 0 0 $command_int_parameter -1";
                                }
                            }else
                                if(preg_match('/^input_link/', $command_text)) {

                                    $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                                    mysql_query($query);

                                    if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {
                                        $message = "main";
                                    } else {

                                        $strLink = trim($message);

                                        $strDel = array("\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*",","," ");
                                        $iCount = count($strDel);
                                        for($i=0;$i<$iCount;$i++) {
                                            $strLink = str_replace($strDel[$i],'',$strLink);
                                        }
                                        $strLink = trim($strLink);

                                        $query = "SELECT * FROM sp_product WHERE product_link='$strLink';";

                                        $resInfo = mysql_query($query);
                                        $rowProduct = mysql_fetch_array($resInfo);

                                        mysql_free_result($resInfo);
                                        if($rowProduct) {
                                            $product_name = $rowProduct['product_name'];

                                            $dataSend = array(
                                                'text' => "Ссылка \"<b>" . $strLink . "</b>\" уже присутствует в системе, имя товара <b>$product_name</b>. Повторно дабавлена не будет. Если ссылка добавлена но не появилась в продуктах - значит модератор её ещё не обработал.",
                                                'chat_id' => $chat_id,
                                                'disable_web_page_preview' => true,
                                                'parse_mode' => 'html'
                                            );
                                            $this->requestToTelegram($dataSend, "sendMessage");

                                            $message = "main";

                                        } else {


                                            $query = "INSERT INTO sp_product (product_link) values ('$strLink');";
                                            mysql_query($query);



                                            $dataSend = array(
                                                'text' => "Вы добавили ссылку <b>$strLink</b> она появится после обработки и проверки модератором. Спасибо за заполнение бота!",
                                                'chat_id' => $chat_id,
                                                'disable_web_page_preview' => true,
                                                'parse_mode' => 'html'
                                            );

                                            $this->setFileLog("Пользователь $users_id (админ $users_admin) $first_name $last_name $username добавил ссылку '$strLink' в систему");

                                            $this->requestToTelegram($dataSend, "sendMessage");

                                            $message = "main";
                                        }
                                    }
                                } else
                                    if(preg_match('/^input_name_category/', $command_text)) {

                                        $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                                        mysql_query($query);

                                        if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {
                                            $message = "main";
                                        } else {

                                            $strCategoryName = trim($message);

                                            $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                                            $iCount = count($strDel);
                                            for($i=0;$i<$iCount;$i++) {
                                                $strCategoryName = str_replace($strDel[$i],'',$strCategoryName);
                                            }
                                            $strCategoryName = trim($strCategoryName);


                                            $query = "SELECT * FROM sp_category WHERE category_id=$command_int_parameter;";

                                            $resInfo = mysql_query($query);
                                            $rowProduct = mysql_fetch_array($resInfo);
                                            mysql_free_result($resInfo);

                                            $category_name = $rowProduct['category_name'];

                                            $query = "UPDATE sp_category SET category_name='$strCategoryName' WHERE category_id=$command_int_parameter;";

                                            mysql_query($query);


                                            $dataSend = array(
                                                'text' => "Вы изменили имя категории <b>$category_name</b> на \"<b>" . $strCategoryName . "</b>\".",
                                                'chat_id' => $chat_id,
                                                'parse_mode' => 'html'
                                            );

                                            $this->setFileLog("Пользователь $users_id (админ $users_admin) $first_name $last_name $username изменил имя категории id $command_int_parameter '$category_name' на '$strCategoryName'");

                                            $this->requestToTelegram($dataSend, "sendMessage");

                                            $message = "all_product 0 0 $command_int_parameter -1";
                                        }
                                    }else
                                        if(preg_match('/^delete_category/', $command_text)) {

                                            $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                                            mysql_query($query);

                                            if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {
                                                $message = "main";
                                            } else if(preg_match('/^Удалить/', $message) || preg_match('/^удалить/', $message)) {


                                                $query = "SELECT * FROM sp_category WHERE category_id=$command_int_parameter;";

                                                $resInfo = mysql_query($query);
                                                $rowProduct = mysql_fetch_array($resInfo);
                                                mysql_free_result($resInfo);

                                                $category_name = $rowProduct['category_name'];



                                                $query = "DELETE FROM sp_category WHERE category_id=$command_int_parameter;";
                                                mysql_query($query);

                                                $query = "UPDATE sp_category SET category_parent=NULL WHERE category_parent=$command_int_parameter;";
                                                mysql_query($query);


                                                $dataSend = array(
                                                    'text' => "Вы удалили категорию <b>$category_name</b> Все категории которые в ней были перемещены в главное меню.",
                                                    'chat_id' => $chat_id,
                                                    'parse_mode' => 'html'
                                                );

                                                $this->setFileLog("Пользователь $users_id (админ $users_admin) $first_name $last_name $username удалил категорию id $command_int_parameter '$category_name'");

                                                $this->requestToTelegram($dataSend, "sendMessage");

                                                $message = "main";
                                            }
                                        } else
                                            if(preg_match('/^clear_category/', $command_text)) {

                                                $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                                                mysql_query($query);

                                                if(preg_match('/^cancel/', $message) || preg_match('/^Отмена/', $message)) {
                                                    $message = "main";
                                                } else if(preg_match('/^Очистить/', $message) || preg_match('/^очистить/', $message)) {

                                                    $query = "SELECT * FROM sp_category WHERE category_id=$command_int_parameter;";

                                                    $resInfo = mysql_query($query);
                                                    $rowProduct = mysql_fetch_array($resInfo);
                                                    mysql_free_result($resInfo);

                                                    $category_name = $rowProduct['category_name'];


                                                    $query = "UPDATE sp_category SET category_parent=NULL WHERE category_parent=$command_int_parameter;";
                                                    mysql_query($query);


                                                    $dataSend = array(
                                                        'text' => "Вы очистили категорию <b>$category_name</b> Все категории которые в ней были перемещены в главное меню.",
                                                        'chat_id' => $chat_id,
                                                        'parse_mode' => 'html'
                                                    );

                                                    $this->setFileLog("Пользователь $users_id (админ $users_admin) $first_name $last_name $username очистил категорию id $command_int_parameter '$category_name'");

                                                    $this->requestToTelegram($dataSend, "sendMessage");

                                                    $message = "main";
                                                }
                                            }

            }
        }
        //Функция для получения значений настроек которые мы ранее могли наклацать.
        $query = "SELECT * FROM sp_settings WHERE settings_users_id='$users_id';";
        $resInfo = mysql_query($query);

        $iCount = count($arrSettings);
        while($rowProduct = mysql_fetch_array($resInfo)) {
            for($i=0;$i<$iCount;$i++) {
                if($arrSettings[$i]['name'] == $rowProduct['settings_name']) {
                    if($rowProduct['settings_is_string'] == 1)
                        $arrSettings[$i]['param'] = $rowProduct['settings_str_value'];
                    else
                        $arrSettings[$i]['param'] = $rowProduct['settings_int_value'];

                    $arrSettings[$i]['id'] = $rowProduct['settings_id'];
                }
            }
        }
        // $this->setFileLog($arrSettings);
        mysql_free_result($resInfo);



        switch ($message) {
            case (preg_match('/^clear_db/', $message) ? true : false): { //Техническая кнопка, убрать после того как бот будет запущен в работу. Сбрасывает данные о категориях и продуктах.
                $query = "UPDATE sp_category SET category_last_up=NULL,category_product_count=NULL,category_price_min=NULL,category_price_max=NULL;";
                mysql_query($query);

                $query = "UPDATE sp_product SET product_name=NULL,product_time_get=NULL,product_price=NULL,product_category_id=NULL,product_read_file=NULL;";
                mysql_query($query);
                $message = 'main_menu ' . $arrData['callback_query']['message']['message_id'];
            }
            case 'main': //Главное меню и все вариации его вызова
            case '/main':
            case 'menu':
            case '/menu':
            case 'start':
            case '/start':
            case (preg_match('/^main_menu/', $message) ? true : false): {
                $params = explode(" ", $message);

                //Вдруг пользователь не особо сообразительный, таким образом так же будем отменять ввод поисковых слов. Если он откроет меню.
                $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                mysql_query($query);


                if($users_admin) {
                    $inlineKeyboardMain = $this->getInlineKeyBoard([
                        [
                            ['text' => 'Выбрать продукт', 'callback_data' => 'all_product 0 0 -1 -1'] //[имя функции], [текущая страница], [сколько страниц], [родительская категория]
                        ],
                        [
                            ['text' => 'Мои избранные', 'callback_data' => 'my_product 0 0'] //[имя функции], [текущая страница], [сколько страниц]
                        ],
                        [
                            ['text' => 'Быстрый поиск', 'callback_data' => 'quick_search']
                        ],
                        [
                            ['text' => 'Настройки', 'callback_data' => 'my_settings 0 none']
                        ],
                        [
                            ['text' => 'Добавить товар', 'callback_data' => 'add_product -1']
                        ],
                        [
                            ['text' => '✎[Не совмещенная продукция]', 'callback_data' => "uncategorized_products 0 0 -1 none"] // второй параметр это ид товара на который совмещать (если не -1 значит мы зашли в товар сделать возможность вернуться назад в товар)
                        ],
                        [
                            ['text' => '✎[Очистить Базу Данных]', 'callback_data' => "clear_db $params"]
                        ]
                    ]);
                } else {
                    $inlineKeyboardMain = $this->getInlineKeyBoard([
                        [
                            ['text' => 'Выбрать продукт', 'callback_data' => 'all_product 0 0 -1 -1'] //[имя функции], [текущая страница], [сколько страниц], [родительская категория]
                        ],
                        [
                            ['text' => 'Мои избранные', 'callback_data' => 'my_product 0 0'] //[имя функции], [текущая страница], [сколько страниц]
                        ],
                        [
                            ['text' => 'Быстрый поиск', 'callback_data' => 'quick_search']
                        ],
                        [
                            ['text' => 'Настройки', 'callback_data' => 'my_settings 0 none']
                        ]
                    ]);
                }



                if($params[1] > 0) {//Если это возврат из другого меню - перересовываем.

                    $dataSend = array(
                        'text' => "Главное меню:",
                        'chat_id' => $chat_id,
                        'message_id' => $params[1]
                    );

                    $this->requestToTelegram($dataSend, "editMessageText");

                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardMain,
                        'message_id' => $params[1]
                    );

                    $this->requestToTelegram($dataSend, "editMessageReplyMarkup");

                } else { //Если это первый вызов значит просто отображаем меню.

                    $dataSend = array(
                        'text' => "Главное меню:",
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardMain
                    );

                    $this->requestToTelegram($dataSend, "sendMessage");

                }
                break;
            }
            case (preg_match('/^add_product/', $message) ? true : false): {
                $params = explode(" ", $message);
                $iCategoryId = $params[1];


                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");


                $query = "INSERT INTO sp_command (command_users_id, command_text, command_int_parameter) values ('$users_id', 'input_link','$iCategoryId');";
                mysql_query($query);


                $strSearchMessage = "Для добавления продукта укажите полную ссылку на страницу (скопировать из браузера). 
				После проверки и обработки модератором ссылка будет добавлена в существующею категорию или будет создана новая.
				
				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^input_search_product/', $message) ? true : false): { //Отлавливает введение запроса. (Здесь будем записывать данные что мы ждём от пользователя ввод поисковых слов.)

                $params = explode("|", $message);


                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");



                $query = "INSERT INTO sp_command (command_users_id, command_text,command_str_parameter) values ('$users_id', 'input_search_product','" . $params[1] . "');";
                mysql_query($query);


                $strSearchMessage = "Введите запрос в чат и отправьте боту.
				
				\rВводите ключевые слова через пробел. Если предыдущий поиск не сброшен можете написать только недостающие слова.

				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^input_search/', $message) ? true : false): { //Отлавливает введение запроса. (Здесь будем записывать данные что мы ждём от пользователя ввод поисковых слов.)



                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");



                $query = "INSERT INTO sp_command (command_users_id, command_text) values ('$users_id', 'input_search');";
                mysql_query($query);


                $strSearchMessage = "Введите запрос в чат и отправьте боту.
				
				\rВводите ключевые слова через пробел. Если предыдущий поиск не сброшен можете написать только недостающие слова.

				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^add_category/', $message) ? true : false): {
                $params = explode(" ", $message);
                $iCategoryId = $params[1];


                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");


                $query = "INSERT INTO sp_command (command_users_id, command_text, command_int_parameter) values ('$users_id', 'input_category','$iCategoryId');";
                mysql_query($query);


                $strSearchMessage = "Напишите название категории которую хотите добавить. Категория будет вложена в родительскую категорию на данный момент.
				
				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^rename_category/', $message) ? true : false): { //Переименование категории
                $params = explode(" ", $message);
                $iCategoryId = $params[1];


                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");


                $query = "INSERT INTO sp_command (command_users_id, command_text, command_int_parameter) values ('$users_id', 'input_name_category','$iCategoryId');";
                mysql_query($query);


                $strSearchMessage = "Вы изменяете имя родительской категории. Напишите новое название категории.
				
				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^delete_category/', $message) ? true : false): { //Удаление категории
                $params = explode(" ", $message);
                $iCategoryId = $params[1];


                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");


                $query = "INSERT INTO sp_command (command_users_id, command_text, command_int_parameter) values ('$users_id', 'delete_category','$iCategoryId');";
                mysql_query($query);

                $query = "SELECT * FROM sp_category WHERE category_id=$iCategoryId;";
                $resInfo = mysql_query($query);
                $rowProduct = mysql_fetch_array($resInfo);
                $category_name = $rowProduct['category_name'];
                mysql_free_result($resInfo);


                $strSearchMessage = "Вы точно хотите <b>удалить</b> категорию <b>$category_name</b>? 
				Для подтверждения напишите <b>удалить</b>.
				
				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Удалить"
                            ),
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^clear_category/', $message) ? true : false): { //Очищаем категорию (Перемещаем всё что в ней было в главное меню).
                $params = explode(" ", $message);
                $iCategoryId = $params[1];


                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");


                $query = "INSERT INTO sp_command (command_users_id, command_text, command_int_parameter) values ('$users_id', 'clear_category','$iCategoryId');";
                mysql_query($query);

                $query = "SELECT * FROM sp_category WHERE category_id=$iCategoryId;";
                $resInfo = mysql_query($query);
                $rowProduct = mysql_fetch_array($resInfo);
                $category_name = $rowProduct['category_name'];
                mysql_free_result($resInfo);


                $strSearchMessage = "Вы точно хотите <b>очистить</b> категорию <b>$category_name</b> и переместить все вложенные в неё категории в главное меню? 
				Для подтверждения напишите <b>Очистить</b>.
				
				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Очистить"
                            ),
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^input_review/', $message) ? true : false): { //Отлавливает введение комментария
                $params = explode(" ", $message);
                $iCategoryId = $params[1];
                $strMenuParent = $params[2];


                $dataSend = array(
                    'chat_id' => $chat_id,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "deleteMessage");



                $query = "INSERT INTO sp_command (command_users_id, command_text, command_int_parameter,command_str_parameter) values ('$users_id', 'input_review','$iCategoryId','$strMenuParent');";
                mysql_query($query);


                $strSearchMessage = "<b>Если нашли ошибку или хотите высказать своё мнение, пожелание или виденье - сообщите нам! Спасибо.</b>

				Введите отзыв или комментарий в чат и отправьте боту.
				
				\rДля отмены напишите <b> /canсel </b> или нажмите кнопку <b>Отмена</b>";

                $keyboard = array(
                    "keyboard" => array(
                        array(
                            array(
                                "text" => "Отмена"
                            )
                        )
                    ),
                    "one_time_keyboard" => true, // можно заменить на FALSE,клавиатура скроется после нажатия кнопки автоматически при True
                    "resize_keyboard" => true // можно заменить на FALSE, клавиатура будет использовать компактный размер автоматически при True
                );

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'text' => $strSearchMessage,
                    'parse_mode' => 'html',
                    'reply_markup' => json_encode($keyboard)
                );
                $this->requestToTelegram($dataSend, "sendMessage");

                break;
            }
            case (preg_match('/^my_settings/', $message) ? true : false): {
                $params = explode(" ", $message);
                $iNumSettings = $params[1];
                $strActionSettings = $params[2];

                $query = "";
                if(!preg_match('/^none/', $strActionSettings)) {
                    if(preg_match('/^more/', $strActionSettings)) {
                        $arrSettings[$iNumSettings]['param'] = $arrSettings[$iNumSettings]['param'] + 1;
                    } else
                        if(preg_match('/^less/', $strActionSettings)) {
                            $arrSettings[$iNumSettings]['param'] = $arrSettings[$iNumSettings]['param'] - 1;
                        }

                    if($arrSettings[$iNumSettings]['param'] > 10)
                        $arrSettings[$iNumSettings]['param'] = 10;
                    if($arrSettings[$iNumSettings]['param'] <= 0)
                        $arrSettings[$iNumSettings]['param'] = 1;




                    // $query = "SELECT * FROM sp_settings WHERE settings_users_id=$chat_id AND settings_name='" . $arrSettings[$iNumSettings]['name'] . "'";
                    // $selectSettings = mysql_query($query);
                    // $rowSettings = mysql_fetch_array($selectSettings);
                    // mysql_free_result($selectSettings);

                    if($arrSettings[$iNumSettings]['id'] != -1) {
                        $query = "UPDATE sp_settings SET settings_int_value='" . $arrSettings[$iNumSettings]['param'] . "' WHERE settings_id='" . $arrSettings[$iNumSettings]['id'] . "';";
                        mysql_query($query);

                    } else {
                        $query = "INSERT INTO sp_settings (settings_users_id,settings_name, settings_is_string, settings_int_value) values ('$users_id','" . $arrSettings[$iNumSettings]['name'] . "', '0','" . $arrSettings[$iNumSettings]['param'] . "');";
                        mysql_query($query);
                    }
                }


                $arrSettingsMenu;
                $Iterator = 0;


                $strSettingsMessage = "<b>Настройки:</b>
Выберите настройку стрелками вверх/вниз и изменяйте её значение.";

                if($iNumSettings == 0) $strSettingsMessage = $strSettingsMessage . "<b>";
                $strSettingsMessage = $strSettingsMessage . "
Количество записей на странице: " . $arrSettings[0]['param'];
                if($iNumSettings == 0) $strSettingsMessage = $strSettingsMessage . "</b>";


                $dataSend = array(
                    'text' => $strSettingsMessage,
                    'chat_id' => $chat_id,
                    'parse_mode' => 'html',
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );
                $this->requestToTelegram($dataSend, "editMessageText");



                $arrSettingsMenu[$Iterator++] = array(array("text" => "←", "callback_data" => "my_settings $iNumSettings less"), array("text" => "→", "callback_data" => "my_settings $iNumSettings more"));
                $arrSettingsMenu[$Iterator++] = array(array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $arrData['callback_query']['message']['message_id']));


                $inlineKeyboardSearch = $this->getInlineKeyBoard($arrSettingsMenu);

                $dataSend = array(
                    'chat_id' => $chat_id,
                    'reply_markup' => $inlineKeyboardSearch,
                    'message_id' => $arrData['callback_query']['message']['message_id']
                );

                $this->requestToTelegram($dataSend, "editMessageReplyMarkup");

                break;
            }
            case (preg_match('/^uncategorized_products/', $message) ? true : false): {
                //uncategorized_products page allPage IdCategoryParr ParrentMenu
                $params = explode(" ", $message);

                $iParrCategory = $params[3];
                $strParrentMenu = $params[4];
                $iProductId = $params[5];
                $strSaveProduct = $params[6];


                $category_name = "";
                $category_product_count = -1;
                $category_price_min = 0;
                $category_price_max = 0;



                if($iParrCategory != 0 && $iParrCategory != -1 && $iProductId != 0 && !(preg_match('/^save/', $message)?true:false)) {

                    $query = "SELECT * FROM sp_product WHERE product_id='$iProductId';";
                    $resInfo = mysql_query($query);
                    $rowProduct = mysql_fetch_array($resInfo);

                    if($rowProduct) {
                        $product_id = $rowProduct['product_id'];
                        $product_name = $rowProduct['product_name'];
                        $product_price = $rowProduct['product_price'];
                        $product_category_id = $rowProduct['product_category_id'];

                        if($product_category_id <= 0 || $product_category_id == NULL) {

                            if($product_price > 0 && $product_price != NULL) {

                                if(strlen($product_name) >= 5) {

                                    //Ставим пометку что этот товар уже не без категории.
                                    $query = "UPDATE sp_product SET product_category_id='$iParrCategory',product_time_get=NULL WHERE product_id='$product_id';";
                                    mysql_query($query);

                                    //Записываем совмещать такие же название на будущее
                                    $query = "INSERT INTO sp_combination_product (compr_name,compr_category_id) values ('$product_name', '$iParrCategory');";
                                    mysql_query($query);


                                    $query = "SELECT * FROM sp_category WHERE category_id=$iParrCategory;";

                                    $resInfo = mysql_query($query) or $ErrorQuery = 1;
                                    $rowSelect = mysql_fetch_array($resInfo);
                                    if($rowSelect) {

                                        $category_name = $rowSelect['category_name'];

                                        $category_product_count = $rowSelect['category_product_count'];
                                        $category_price_min = $rowSelect['category_price_min'];
                                        $category_price_max = $rowSelect['category_price_max'];

                                        $category_product_count++;

                                        //Обновляем количество товара категории. Цену не трогаем до её получения автоматически с сайта.
                                        $query = "UPDATE sp_category SET category_product_count='$category_product_count',category_last_up=NULL WHERE category_id='$iParrCategory';";
                                        mysql_query($query);

                                    }
                                }
                            }
                        }
                    }
                }


                if($iProductId > 0 && preg_match('/^save/', $strSaveProduct)) { //Здесь будем сужать поиск! Это важно!

                    $query = "SELECT * FROM sp_product WHERE product_id=$iProductId;";

                    $resInfo = mysql_query($query);
                    $rowSelect = mysql_fetch_array($resInfo);
                    if($rowSelect) {

                        $product_name = $rowSelect['product_name'];


                        // $dataSend = array(
                        // 'text' => "1 " . $query,
                        // 'chat_id' => $chat_id,
                        // );
                        $this->requestToTelegram($dataSend, "sendMessage");
                        // Сначала удаляем всё лишнее, что бы не было конфликта
                        $query = "DELETE FROM sp_search WHERE search_user_id='$users_id' AND search_type='product_search';";
                        mysql_query($query);


                        $search_text = trim($product_name);

                        $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                        $iCount = count($strDel);
                        for($i=0;$i<$iCount;$i++) {
                            $search_text = str_replace($strDel[$i],' ',$search_text);
                        }
                        $search_text = trim($search_text);

                        $arrParamSearch = explode(" ", $search_text);

                        $iCount = count($arrParamSearch);
                        for($i=0;$i<$iCount;$i++) {
                            $arrParamSearch[$i] = trim($arrParamSearch[$i]);
                        }

                        $arrParamSearch = array_unique($arrParamSearch); //Удаляем повторы на всякий случай.


                        $strText = "";
                        $iCount = count($arrParamSearch);
                        for($i=0;$i<$iCount;$i++) {
                            $strText = $strText . " " . $arrParamSearch[$i];
                        }


                        $query = "INSERT INTO sp_search (search_user_id,search_timestamp, search_text, search_type) values ('$users_id', '$iTimestamp','$strText','product_search');";
                        mysql_query($query);
                    }

                }


                //Если ключевые слова не введены.
                $strSelectSearch = "SELECT * FROM sp_product WHERE product_category_id IS NULL AND product_price>'0' AND product_price IS NOT NULL AND LENGTH(product_name)>=6;";


                $pos = strpos($message, 'clear');
                if($pos !== false) { //Если история поиска очищена значит удаляем все в бд где есть ид текущего пользователя.
                    $query = "DELETE FROM sp_search WHERE search_user_id='$users_id' AND search_type='product_search';";
                    mysql_query($query);
                } else {

                    $query = "SELECT * FROM sp_search WHERE search_user_id='$users_id' AND search_type='product_search';";
                    $arrParamSearch = "";
                    $iSearchCount = 0;

                    $resInfo = mysql_query($query) or $ErrorQuery = 1;
                    if(!$ErrorQuery) {
                        $iCountSearchRow = mysql_num_rows($resInfo); // 6

                        if($iCountSearchRow > 0) {

                            while($rowProduct = mysql_fetch_array($resInfo)) {
                                $strParam = explode(" ",$rowProduct['search_text']);

                                $iCountParam = count($strParam);
                                for($i=0;$i<$iCountParam;$i++) {
                                    $arrParamSearch[$iSearchCount] = $strParam[$i];
                                    $iSearchCount++;

                                }
                            }
                            mysql_free_result($resInfo);

                            $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                            $iCount = count($strDel);
                            for($j=0;$j<$iSearchCount;$j++) {

                                for($i=0;$i<$iCount;$i++) {
                                    $arrParamSearch[$j] = trim(str_replace($strDel[$i],"",$arrParamSearch[$j]));
                                }
                            }

                            for($i=0;$i<$iSearchCount;$i++) {
                                $arrParamSearch[$i] = trim($arrParamSearch[$i]);
                            }

                            $arrParamSearch = array_unique($arrParamSearch); //Удаляем повторы на всякий случай.


                            $strSelectSearch = "SELECT * FROM sp_product WHERE product_category_id IS NULL AND product_price>'0' AND product_price IS NOT NULL AND LENGTH(product_name)>=6 AND ";


                            for($i=0;$i<$iSearchCount;$i++) {
                                if($i + 1 >= $iSearchCount) {
                                    $strSelectSearch = $strSelectSearch . "product_name LIKE '%" . $arrParamSearch[$i] . "%';";
                                    break;
                                }
                                else {
                                    if($arrParamSearch[$i] != " ") {
                                        $strSelectSearch = $strSelectSearch . "product_name LIKE '%" . $arrParamSearch[$i] . "%' AND ";
                                    }

                                }

                            }
                        } else {
                            mysql_free_result($resInfo);
                        }
                    }

                }

                $iAllPage = 0; //Сколько всего страниц
                $iPage = 0; //Страница
                $iCountOnPage = $arrSettings[0]['param']; //Здесь количество записей на странице (будет браться из настроек)
                $iCountRows = 0; //Здесь будет общее количество записей
                $iIterator = 0; //Это будет счетчик цикла

                $iStart = 0; //Начальная с которой считать на странице
                $iEnd = 0; //Конечная до которой отображаем на странице




                $arrSaveProduct;

                $resInfo = mysql_query($strSelectSearch) or $ErrorQuery = 1;

                if(!$ErrorQuery) {
                    $iCountRows = mysql_num_rows($resInfo); // 6

                    $iCounterElem = 0;
                    while($rowProduct = mysql_fetch_array($resInfo)) {
                        $arrSaveProduct[$iCounterElem] = $rowProduct;
                        $iCounterElem++;
                    }
                    mysql_free_result($resInfo);

                    //Если страница задана и из скольки задана получаем их.
                    if($params[1] >= 0) {
                        $iPage = $params[1];
                    }
                    if($params[2] > 0)
                        $iAllPage = $params[2];

                    if($iPage >= $iAllPage)
                        $iPage = $iAllPage - 1;

                    if($iPage < 0)
                        $iPage = 0;

                }



                $arrSearchMenu;
                $Iterator = 0;



                //Здесь будет расчитывать сколько у нас страниц и какая сейчас из скольки.

                //   4      = мин ( 2    *   2          , 6          )
                $iIterator = min($iPage * $iCountOnPage, $iCountRows); // 2
                $iSaveIterator = $iIterator;
                $iSavePage = $iPage;
                $iSaveAllPage = $iAllPage;
                //   2   =      4     - (    4       %     2        )
                $iStart = $iIterator - ($iIterator % $iCountOnPage);

                //  4   =       2    +     2      ,      6
                $iEnd = min($iStart + $iCountOnPage, $iCountRows);
                //				2		/      2
                $iPage = floor($iStart / $iCountOnPage);

                $iAllPage = floor((($iCountRows - 1) / $iCountOnPage) + 1);


                $iCount = count($arrSaveProduct);
                $iEnd = min($iEnd,$iCount);
                for($iIterator = $iStart;$iIterator<$iEnd;$iIterator++) {

                    $product_id = $arrSaveProduct[$iIterator]['product_id'];
                    $product_name = $arrSaveProduct[$iIterator]['product_name'];
                    $product_price = $arrSaveProduct[$iIterator]['product_price'];

                    if($iParrCategory >= 0)
                        $arrSearchMenu[$Iterator++][0] =array('text' => "$product_name  [$product_price]", 'callback_data' => "uncategorized_products 0 0 $iParrCategory $strParrentMenu $product_id");
                    else
                        $arrSearchMenu[$Iterator++][0] =array('text' => "$product_name  [$product_price]", 'callback_data' => "product_param |$iPage $iAllPage $iParrCategory $strParrentMenu $product_id");
                }






                $strSearchMessage = "";
                if($iParrCategory > 0) {

                    if($category_product_count == -1) {
                        $query = "SELECT * FROM sp_category WHERE category_id=$iParrCategory;";

                        $resInfo = mysql_query($query) or $ErrorQuery = 1;


                        $rowSelect = mysql_fetch_array($resInfo);
                        if($rowSelect) {
                            $category_name = $rowSelect['category_name'];

                            $category_product_count = $rowSelect['category_product_count'];
                            $category_price_min = $rowSelect['category_price_min'];
                            $category_price_max = $rowSelect['category_price_max'];


                        }
                    }


                    $strSearchMessage = "Название: <b>$category_name</b>
					Цена: <b>$category_price_min</b> - <b>$category_price_max</b>
					Товаров: <b>$category_product_count</b>
					";
                }

                $strSearchMessage = $strSearchMessage . "Несовмещённые продукты: (" . (($iPage+1) ? ($iPage+1):"1") .  "/" . $iAllPage . "):";

                if($iCountSearchRow > 0) {
                    $strSearchMessage = $strSearchMessage . "
\rТекущий запрос: <b><i>";




                    for($i=0;$i<$iSearchCount;$i++) {
                        if($i + 1 >= $iSearchCount) {
                            $strSearchMessage = $strSearchMessage . $arrParamSearch[$i] . "</i></b>;";
                            break;
                        }
                        else {
                            if($arrParamSearch[$i] != " ") {
                                $strSearchMessage = $strSearchMessage . $arrParamSearch[$i] . ", ";
                            }

                        }

                    }


                    if($iAllPage <= 0) {
                        $strSearchMessage = $strSearchMessage . "

\r<b>По запросу не чего не найдено. Очистите запрос!</b>";

                    }


                }



                $dataSend;
                if($arrData['callback_query']['message']['message_id'] > 0) {

                    $dataSend = array(
                        'text' => $strSearchMessage,
                        'chat_id' => $chat_id,
                        'parse_mode' => 'html',
                        'message_id' => $arrData['callback_query']['message']['message_id']
                    );
                    $this->requestToTelegram($dataSend, "editMessageText");
                } else {
                    $dataSend = array(
                        'text' => $strSearchMessage,
                        'chat_id' => $chat_id,
                        'parse_mode' => 'html'
                    );


                    $this->requestToTelegram($dataSend, "sendMessage");

                }



                if($iCountSearchRow > 0)
                    $arrSearchMenu[$Iterator++] = array(array('text' => 'Очистить поиск', 'callback_data' => "uncategorized_products 0 0 $iParrCategory $strParrentMenu clear"));


                if($iCountRows > 1 || $iCountSearchRow <= 0)
                    $arrSearchMenu[$Iterator++] = array(array('text' => 'Сузить поиск', 'callback_data' => "input_search_product|$iParrCategory $strParrentMenu"));


                $strBack = "uncategorized_products " . ($iPage - 1) . " $iAllPage $iParrCategory $strParrentMenu";

                if($iPage+1 <= 0)
                    $iPage = 0;

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $iIdMessageMenu = $arrData['callback_query']['message']['message_id'];
                } else {
                    $iIdMessageMenu = $arrData['message']['message_id']+1;
                }

                if($iParrCategory > 0)
                    $arrSearchMenu[$Iterator++] = array(array('text' => 'Назад', 'callback_data' => "product_info $iParrCategory 0 $strParrentMenu"));

                $strForward = "uncategorized_products " . ($iPage + 1) . " $iAllPage $iParrCategory $strParrentMenu";
                $arrSearchMenu[$Iterator++] = array(array('text' => '←', 'callback_data' => $strBack),array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $iIdMessageMenu),array('text' => '→', 'callback_data' => $strForward));


                $inlineKeyboardSearch = $this->getInlineKeyBoard($arrSearchMenu);

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardSearch,
                        'message_id' => $arrData['callback_query']['message']['message_id']
                    );
                } else {
                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardSearch,
                        'message_id' => $arrData['message']['message_id']+1
                    );

                }

                $this->requestToTelegram($dataSend, "editMessageReplyMarkup");

                break;
            }
            case (preg_match('/^delcategorized_products/', $message) ? true : false): {
                //delcategorized_products page allPage IdCategoryParr ParrentMenu
                $params = explode(" ", $message);

                $iParrCategory = $params[3];
                $strParrentMenu = $params[4];
                $iProductId = $params[5];


                $category_name = "";
                $category_product_count = -1;
                $category_price_min = 0;
                $category_price_max = 0;


                //Здесь будет удаление продукта из категории.
                if($iProductId != 0) {
                    $query = "SELECT * FROM sp_product WHERE product_id='$iProductId';";
                    $resInfo = mysql_query($query);
                    $rowProduct = mysql_fetch_array($resInfo);

                    if($rowProduct) {
                        $product_id = $rowProduct['product_id'];
                        $product_name = $rowProduct['product_name'];
                        $product_price = $rowProduct['product_price'];
                        $product_category_id = $rowProduct['product_category_id'];

                        //Ставим пометку что этот товар уже не без категории.
                        $query = "UPDATE sp_product SET product_category_id=NULL WHERE product_id='$product_id';";
                        mysql_query($query);

                        //Записываем совмещать такие же название на будущее
                        $query = "DELETE * FROM sp_combination_product WHERE compr_name='$product_name';";
                        mysql_query($query);


                        $query = "SELECT * FROM sp_category WHERE category_id=$iParrCategory;";

                        $resInfo = mysql_query($query) or $ErrorQuery = 1;
                        $rowSelect = mysql_fetch_array($resInfo);
                        if($rowSelect) {

                            $category_name = $rowSelect['category_name'];

                            $category_product_count = $rowSelect['category_product_count'];
                            $category_price_min = $rowSelect['category_price_min'];
                            $category_price_max = $rowSelect['category_price_max'];

                            $category_product_count--;
                            if($category_product_count <= 0)
                                $category_product_count = 0;

                            //Обновляем количество товара категории. Цену не трогаем до её получения автоматически с сайта.
                            $query = "UPDATE sp_category SET category_product_count='$category_product_count',category_last_up=NULL WHERE category_id='$iParrCategory';";
                            mysql_query($query);

                        }
                    }
                }


                //Если ключевые слова не введены.
                $strSelectSearch = "SELECT * FROM sp_product WHERE product_category_id=$iParrCategory;";




                $iAllPage = 0; //Сколько всего страниц
                $iPage = 0; //Страница
                $iCountOnPage = $arrSettings[0]['param']; //Здесь количество записей на странице (будет браться из настроек)
                $iCountRows = 0; //Здесь будет общее количество записей
                $iIterator = 0; //Это будет счетчик цикла

                $iStart = 0; //Начальная с которой считать на странице
                $iEnd = 0; //Конечная до которой отображаем на странице




                $arrSaveProduct;

                $resInfo = mysql_query($strSelectSearch) or $ErrorQuery = 1;

                if(!$ErrorQuery) {
                    $iCountRows = mysql_num_rows($resInfo); // 6

                    $iCounterElem = 0;
                    while($rowProduct = mysql_fetch_array($resInfo)) {
                        $arrSaveProduct[$iCounterElem] = $rowProduct;
                        $iCounterElem++;
                    }
                    mysql_free_result($resInfo);

                    //Если страница задана и из скольки задана получаем их.
                    if($params[1] >= 0) {
                        $iPage = $params[1];
                    }
                    if($params[2] > 0)
                        $iAllPage = $params[2];

                    if($iPage >= $iAllPage)
                        $iPage = $iAllPage - 1;

                    if($iPage < 0)
                        $iPage = 0;

                }



                $arrSearchMenu;
                $Iterator = 0;



                //Здесь будет расчитывать сколько у нас страниц и какая сейчас из скольки.

                //   4      = мин ( 2    *   2          , 6          )
                $iIterator = min($iPage * $iCountOnPage, $iCountRows); // 2
                $iSaveIterator = $iIterator;
                $iSavePage = $iPage;
                $iSaveAllPage = $iAllPage;
                //   2   =      4     - (    4       %     2        )
                $iStart = $iIterator - ($iIterator % $iCountOnPage);

                //  4   =       2    +     2      ,      6
                $iEnd = min($iStart + $iCountOnPage, $iCountRows);
                //				2		/      2
                $iPage = floor($iStart / $iCountOnPage);

                $iAllPage = floor((($iCountRows - 1) / $iCountOnPage) + 1);


                $iCount = count($arrSaveProduct);
                $iEnd = min($iEnd,$iCount);
                for($iIterator = $iStart;$iIterator<$iEnd;$iIterator++) {

                    $product_id = $arrSaveProduct[$iIterator]['product_id'];
                    $product_name = $arrSaveProduct[$iIterator]['product_name'];
                    $product_price = $arrSaveProduct[$iIterator]['product_price'];

                    $arrSearchMenu[$Iterator++][0] =array('text' => "Удалить $product_name  [$product_price]", 'callback_data' => "delcategorized_products 0 0 $iParrCategory $strParrentMenu $product_id");
                }






                $strSearchMessage = "";
                if($iParrCategory > 0) {

                    if($category_product_count == -1) {
                        $query = "SELECT * FROM sp_category WHERE category_id=$iParrCategory;";

                        $resInfo = mysql_query($query) or $ErrorQuery = 1;


                        $rowSelect = mysql_fetch_array($resInfo);
                        if($rowSelect) {
                            $category_name = $rowSelect['category_name'];

                            $category_product_count = $rowSelect['category_product_count'];
                            $category_price_min = $rowSelect['category_price_min'];
                            $category_price_max = $rowSelect['category_price_max'];


                        }
                    }


                    $strSearchMessage = "Название: <b>$category_name</b>
					Цена: <b>$category_price_min</b> - <b>$category_price_max</b>
					Товаров: <b>$category_product_count</b>
					";
                }

                $strSearchMessage = $strSearchMessage . "Текущие совмещения: (" . (($iPage+1) ? ($iPage+1):"1") .  "/" . $iAllPage . "):";


                $dataSend;
                if($arrData['callback_query']['message']['message_id'] > 0) {

                    $dataSend = array(
                        'text' => $strSearchMessage,
                        'chat_id' => $chat_id,
                        'parse_mode' => 'html',
                        'message_id' => $arrData['callback_query']['message']['message_id']
                    );
                    $this->requestToTelegram($dataSend, "editMessageText");
                } else {
                    $dataSend = array(
                        'text' => $strSearchMessage,
                        'chat_id' => $chat_id,
                        'parse_mode' => 'html'
                    );


                    $this->requestToTelegram($dataSend, "sendMessage");

                }

                $strBack = "delcategorized_products " . ($iPage - 1) . " $iAllPage $iParrCategory $strParrentMenu";

                if($iPage+1 <= 0)
                    $iPage = 0;

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $iIdMessageMenu = $arrData['callback_query']['message']['message_id'];
                } else {
                    $iIdMessageMenu = $arrData['message']['message_id']+1;
                }

                if($iParrCategory > 0)
                    $arrSearchMenu[$Iterator++] = array(array('text' => 'Назад', 'callback_data' => "product_info $iParrCategory 0 $strParrentMenu"));

                $strForward = "delcategorized_products " . ($iPage + 1) . " $iAllPage $iParrCategory $strParrentMenu";
                $arrSearchMenu[$Iterator++] = array(array('text' => '←', 'callback_data' => $strBack),array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $iIdMessageMenu),array('text' => '→', 'callback_data' => $strForward));


                $inlineKeyboardSearch = $this->getInlineKeyBoard($arrSearchMenu);

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardSearch,
                        'message_id' => $arrData['callback_query']['message']['message_id']
                    );
                } else {
                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardSearch,
                        'message_id' => $arrData['message']['message_id']+1
                    );

                }

                $this->requestToTelegram($dataSend, "editMessageReplyMarkup");

                break;
            }
            case (preg_match('/^quick_search/', $message) ? true : false): {
                $params = explode(" ", $message);

                $strSelectSearch = "";

                $pos = strpos($message, 'clear');
                if($pos !== false) { //Если история поиска очищена значит удаляем все в бд где есть ид текущего пользователя.
                    $query = "DELETE FROM sp_search WHERE search_user_id='$users_id' AND search_type='quick_search';";
                    mysql_query($query);
                } else {

                    $query = "SELECT * FROM sp_search WHERE search_user_id='$users_id' AND search_type='quick_search';";
                    $arrParamSearch = "";
                    $iSearchCount = 0;


                    //Здесь мы получаем товары которые мы получили записи
                    $resInfo = mysql_query($query) or $ErrorQuery = 1;
                    if(!$ErrorQuery) {
                        $iCountSearchRow = mysql_num_rows($resInfo); // 6

                        if($iCountSearchRow > 0) {

                            while($rowProduct = mysql_fetch_array($resInfo)) {
                                $strParam = explode(" ",$rowProduct['search_text']);

                                $iCountParam = count($strParam);
                                for($i=0;$i<$iCountParam;$i++) {
                                    $arrParamSearch[$iSearchCount] = $strParam[$i];
                                    $iSearchCount++;

                                }
                            }
                            mysql_free_result($resInfo);

                            $strDel = array("/","\\","'","\"","SELECT","DELETE","FROM","WHERE","INSERT","INTO","*","=",".",",");
                            $iCount = count($strDel);
                            for($j=0;$j<$iSearchCount;$j++) {

                                for($i=0;$i<$iCount;$i++) {
                                    $arrParamSearch[$j] = trim(str_replace($strDel[$i],"",$arrParamSearch[$j]));
                                }
                            }

                            for($i=0;$i<$iSearchCount;$i++) {
                                $arrParamSearch[$i] = trim($arrParamSearch[$i]);
                            }

                            $arrParamSearch = array_unique($arrParamSearch); //Удаляем повторы на всякий случай.


                            $strSelectSearch = "SELECT * FROM sp_category WHERE category_parent IS NOT NULL AND category_product_count>'0' AND category_product_count IS NOT NULL AND ";


                            for($i=0;$i<$iSearchCount;$i++) {
                                if($i + 1 >= $iSearchCount) {
                                    $strSelectSearch = $strSelectSearch . "category_name LIKE '%" . $arrParamSearch[$i] . "%';";
                                    break;
                                }
                                else {
                                    if($arrParamSearch[$i] != " ") {
                                        $strSelectSearch = $strSelectSearch . "category_name LIKE '%" . $arrParamSearch[$i] . "%' AND ";
                                    }

                                }

                            }
                        } else {
                            mysql_free_result($resInfo);
                        }
                    }

                }

                $iCurrCategory = $params[3];
                $iParentCategory = $params[4]; //Если -1 значит не чего не делаем, если 1 значит ищем куда вложена родительская категория.
                $iSaveCurrCategory = $iCurrCategory; //Сохраняем родительскую категорию, что бы понимать


                $iAllPage = 0; //Сколько всего страниц
                $iPage = 0; //Страница
                $iCountOnPage = $arrSettings[0]['param']; //Здесь количество записей на странице (будет браться из настроек)
                $iCountRows = 0; //Здесь будет общее количество записей
                $iIterator = 0; //Это будет счетчик цикла

                $iStart = 0; //Начальная с которой считать на странице
                $iEnd = 0; //Конечная до которой отображаем на странице




                $arrSaveCategory;

                $resInfo = mysql_query($strSelectSearch) or $ErrorQuery = 1;

                if(!$ErrorQuery) {
                    $iCountRows = mysql_num_rows($resInfo); // 6

                    $iCounterElem = 0;
                    while($rowProduct = mysql_fetch_array($resInfo)) {
                        $arrSaveCategory[$iCounterElem] = $rowProduct;
                        $iCounterElem++;
                    }
                    mysql_free_result($resInfo);

                    if($iParentCategory == 1 && $iSaveCurrCategory >= 0) { //Если пользователь хочет подняться выше на категорию расчитываем на какой странице показать меню.

                        $iCount = count($arrSaveCategory);
                        for($i = 0;$i<$iCount;$i++) {
                            if($arrSaveCategory[$i]['category_id'] == $iSaveCurrCategory) {
                                $iPage = $i / $iCountOnPage;
                                break;
                            }
                        }
                    } else {

                        //Если страница задана и из скольки задана получаем их.
                        if($params[1] >= 0) {
                            $iPage = $params[1];
                        }
                        if($params[2] > 0)
                            $iAllPage = $params[2];

                    }

                    if($iParentCategory != 1) {
                        if($iPage >= $iAllPage)
                            $iPage = $iAllPage - 1;
                    }


                    if($iPage < 0)
                        $iPage = 0;

                }



                $arrSearchMenu;
                $Iterator = 0;



                //Здесь будет расчитывать сколько у нас страниц и какая сейчас из скольки.

                //   4      = мин ( 2    *   2          , 6          )
                $iIterator = min($iPage * $iCountOnPage, $iCountRows); // 2
                $iSaveIterator = $iIterator;
                $iSavePage = $iPage;
                $iSaveAllPage = $iAllPage;
                //   2   =      4     - (    4       %     2        )
                $iStart = $iIterator - ($iIterator % $iCountOnPage);

                //  4   =       2    +     2      ,      6
                $iEnd = min($iStart + $iCountOnPage, $iCountRows);
                //				2		/      2
                $iPage = floor($iStart / $iCountOnPage);

                $iAllPage = floor((($iCountRows - 1) / $iCountOnPage) + 1);


                $iCount = count($arrSaveCategory);
                $iEnd = min($iEnd,$iCount);
                for($iIterator = $iStart;$iIterator<$iEnd;$iIterator++) {

                    // $folowers_category_id = $arrSaveCategory[$iIterator]['folowers_category_id'];
                    // $query = "SELECT * FROM sp_category WHERE category_id='$folowers_category_id';";


                    $category_id = $arrSaveCategory[$iIterator]['category_id'];
                    $category_name = $arrSaveCategory[$iIterator]['category_name'];
                    $category_folowers = $arrSaveCategory[$iIterator]['category_folowers'];
                    $category_product_count = $arrSaveCategory[$iIterator]['category_product_count'];


                    // if($category_product_count >= 0 && $category_product_count != NULL)
                    $arrSearchMenu[$Iterator++][0] =array('text' => $category_name, 'callback_data' => "product_info $category_id 0 quick_search");
                }









                $strSearchMessage = "Быстрый поиск:";

                if($iCountSearchRow > 0) {
                    $strSearchMessage = $strSearchMessage . " (" . (($iPage+1) ? ($iPage+1):"1") .  "/" . $iAllPage . "):
\rТекущий запрос: <b><i>";




                    for($i=0;$i<$iSearchCount;$i++) {
                        if($i + 1 >= $iSearchCount) {
                            $strSearchMessage = $strSearchMessage . $arrParamSearch[$i] . "</i></b>;";
                            break;
                        }
                        else {
                            if($arrParamSearch[$i] != " ") {
                                $strSearchMessage = $strSearchMessage . $arrParamSearch[$i] . ", ";
                            }

                        }

                    }


                    if($iAllPage <= 0) {
                        $strSearchMessage = $strSearchMessage . "

\r<b>По запросу не чего не найдено. Очистите запрос!</b>";

                    }


                }



                $dataSend;
                if($arrData['callback_query']['message']['message_id'] > 0) {

                    $dataSend = array(
                        'text' => $strSearchMessage,
                        'chat_id' => $chat_id,
                        'parse_mode' => 'html',
                        'message_id' => $arrData['callback_query']['message']['message_id']
                    );
                    $this->requestToTelegram($dataSend, "editMessageText");
                } else {
                    $dataSend = array(
                        'text' => $strSearchMessage,
                        'chat_id' => $chat_id,
                        'parse_mode' => 'html'
                    );


                    $this->requestToTelegram($dataSend, "sendMessage");

                }



                if($iCountSearchRow > 0)
                    $arrSearchMenu[$Iterator++] = array(array('text' => 'Очистить поиск', 'callback_data' => "quick_search clear"));


                if($iCountRows > 1 || $iCountSearchRow <= 0)
                    $arrSearchMenu[$Iterator++] = array(array('text' => 'Ввести запрос', 'callback_data' => "input_search"));


                $strBack = "quick_search " . ($iPage - 1) . " $iAllPage";

                if($iPage+1 <= 0)
                    $iPage = 0;

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $iIdMessageMenu = $arrData['callback_query']['message']['message_id'];
                } else {
                    $iIdMessageMenu = $arrData['message']['message_id']+1;
                }

                $strForward = "quick_search " . ($iPage + 1) . " $iAllPage";
                $arrSearchMenu[$Iterator++] = array(array('text' => '←', 'callback_data' => $strBack),array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $iIdMessageMenu),array('text' => '→', 'callback_data' => $strForward));


                $inlineKeyboardSearch = $this->getInlineKeyBoard($arrSearchMenu);

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardSearch,
                        'message_id' => $arrData['callback_query']['message']['message_id']
                    );
                } else {
                    $dataSend = array(
                        'chat_id' => $chat_id,
                        'reply_markup' => $inlineKeyboardSearch,
                        'message_id' => $arrData['message']['message_id']+1
                    );

                }

                $this->requestToTelegram($dataSend, "editMessageReplyMarkup");

                break;
            }
            case (preg_match('/^remove_category/', $message) || preg_match('/^paste_category/', $message)): { //Начало перемещения категории, нужно поставить пометку какую категорюи мы перемещаем.


                $parRemPast = explode(" ", $message);
                $iCategoryId = $parRemPast[1];
                $iParrentCatSave = $parRemPast[2];

                //Здесь мы передавали местоположение в меню и теперь возвращаемся обратно к тому месту где были.
                $params = explode("|", $message);
                $message = trim($params[1]);

                if(preg_match('/^remove_category/', $parRemPast[0])) {

                    $query = "SELECT * FROM sp_category WHERE category_id=$iCategoryId;";
                    $resInfo = mysql_query($query);
                    $rowProduct = mysql_fetch_array($resInfo);
                    $category_name = $rowProduct['category_name'];
                    mysql_free_result($resInfo);

                    $query = "INSERT INTO sp_command (command_users_id,command_text,command_int_parameter,command_str_parameter) values ('$users_id', 'remove_category','$iCategoryId','$category_name');";
                    mysql_query($query);


                    $command_text = "remove_category";
                    $command_int_parameter = $iCategoryId;
                    $command_str_parameter = $category_name;


                } else if(preg_match('/^paste_category/', $parRemPast[0])) {

                    if($iCategoryId == $command_int_parameter) {

                        $iSearchCategory = $iParrentCatSave;

                        if($iSearchCategory == -1 || $iSearchCategory == NULL) {

                            $query = "UPDATE sp_category SET category_parent=NULL WHERE category_id='$iCategoryId';";
                            mysql_query($query);

                        } else
                            if($iSearchCategory != -1) {//Если это не самый верх то нужно проверить не вкладываем ли мы её саму в себя.
                                while($iSearchCategory) { //Будем искать не пытаемся ли мы вложить категорию внутрь категории которая лежит внутри её самой. Такое запрещено!
                                    $query = "SELECT * FROM sp_category WHERE category_id=$iSearchCategory;";

                                    $resInfo = mysql_query($query);
                                    $rowProduct = mysql_fetch_array($resInfo);
                                    $iSearchCategory = $rowProduct['category_parent'];
                                    $iSearchCategoryName = $rowProduct['category_name'];
                                    mysql_free_result($resInfo);

                                    if($iSearchCategory == $iCategoryId) //Если категория вложена сама в себя выкидываем
                                        break;
                                }
                                if($iSearchCategory == -1 || $iSearchCategory == NULL || $iSearchCategory == 0) { //Всё в порядке, проверку прошло на вложенность.
                                    $query = "UPDATE sp_category SET category_parent='$iParrentCatSave' WHERE category_id='$iCategoryId';";
                                    mysql_query($query);
                                }
                            }

                    }

                    $command_id = -1;
                    $command_text = "none";
                    $command_message_id = -1;
                    $command_int_parameter = -1;
                    $command_str_parameter = "";


                    //Независимо от того та эта категория или нет удаляем данные. (Раз ид не совпадает значит он и не сопадёт)
                    $query = "DELETE FROM sp_command WHERE command_users_id='$users_id';";
                    mysql_query($query);
                }

            }
            case 'all_product':
            case (preg_match('/^all_product/', $message) ? true : false): {
                $params = explode(" ", $message);

                $iCurrCategory = $params[3]; //Если -1 значит это самый верх категорий
                $iParentCategory = $params[4]; //Если -1 значит не чего не делаем, если 1 значит ищем куда вложена родительская категория.
                $iSaveCurrCategory = $iCurrCategory; //Сохраняем родительскую категорию, что бы понимать

                //Это все только ради перехода выше в меню!!!!! АААА!! Ужас!
                if($iParentCategory == 1) { //Если хотят перейти на уровень выше в меню.
                    if($iCurrCategory >= 0) {
                        $query = "SELECT * FROM sp_category WHERE category_id=$iCurrCategory;";
                        $resSelect = mysql_query($query) or $ErrorQuery = 1;
                        if(!$ErrorQuery) {
                            $rowSelect = mysql_fetch_array($resSelect);
                            if($rowSelect) {
                                $iCurrCategory = $rowSelect['category_parent'];
                                if($iCurrCategory == NULL)
                                    $iCurrCategory = -1;
                            }
                            mysql_free_result($resSelect);
                        } else {
                            $iCurrCategory = -1;
                        }
                    } else {
                        $iCurrCategory = -1;
                    }
                }


                $iMessageId;

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $iMessageId = $arrData['callback_query']['message']['message_id'];
                } else {
                    $iMessageId = $arrData['message']['message_id']+2;
                }



                $iParrentCatSave = $iSaveCurrCategory; //Что бы сохранять родительскую категорию.

                $iAllPage = 0; //Сколько всего страниц
                $iPage = 0; //Страница
                $iCountOnPage = $arrSettings[0]['param']; //Здесь количество записей на странице
                $iCountRows = 0; //Здесь будет общее количество записей
                $iIterator = 0; //Это будет счетчик цикла

                $iStart = 0; //Начальная с которой считать на странице
                $iEnd = 0; //Конечная до которой отображаем на странице



                $arrAllProduct;
                $Iterator = 0;

                if(!$errorSelect && !$errorConnect) {
                    $query = "";

                    if($iCurrCategory == -1) //Если входим из главного меню.
                        $query = "SELECT * FROM sp_category WHERE category_parent is NULL;";
                    else
                        $query = "SELECT * FROM sp_category WHERE category_parent=$iCurrCategory;";



                    $arrSaveCategory;

                    //Здесь мы получаем товары которые мы получили записи
                    $resInfo = mysql_query($query) or $ErrorQuery = 1;
                    if(!$ErrorQuery) {
                        $iCountRows = mysql_num_rows($resInfo); // 6

                        $iCounterElem = 0;
                        while($rowProduct = mysql_fetch_array($resInfo)) {
                            $arrSaveCategory[$iCounterElem] = $rowProduct;
                            $iCounterElem++;
                        }
                        mysql_free_result($resInfo);

                        if($iParentCategory == 1 && $iSaveCurrCategory >= 0) { //Если пользователь хочет подняться выше на категорию расчитываем на какой странице показать меню.

                            $iCount = count($arrSaveCategory);
                            for($i = 0;$i<$iCount;$i++) {
                                if($arrSaveCategory[$i]['category_id'] == $iSaveCurrCategory) {
                                    $iPage = $i / $iCountOnPage;
                                    break;
                                }
                            }
                        } else {
                            //Если страница задана и из скольки задана получаем их.
                            if($params[1] >= 0) {
                                $iPage = $params[1];
                            }
                            if($params[2] > 0)
                                $iAllPage = $params[2];
                        }

                        if($iParentCategory != 1) {
                            if($iPage >= $iAllPage)
                                $iPage = $iAllPage - 1;
                        }

                        if($iPage < 0)
                            $iPage = 0;



                        //Здесь будет расчитывать сколько у нас страниц и какая сейчас из скольки.
                        //   4      = мин ( 2    *   2          , 6          )
                        $iIterator = min($iPage * $iCountOnPage, $iCountRows); // 2

                        //   2   =      4     - (    4       %     2        )
                        $iStart = $iIterator - ($iIterator % $iCountOnPage);

                        //  4   =       2    +     2      ,      6
                        $iEnd = min($iStart + $iCountOnPage, $iCountRows);
                        //				2		/      2
                        $iPage = floor($iStart / $iCountOnPage);

                        $iAllPage = floor((($iCountRows - 1) / $iCountOnPage) + 1);


                        $iIterator = $iStart;

                        $iCount = count($arrSaveCategory);
                        $iEnd = min($iEnd,$iCount);
                        for($i = $iIterator;$i<$iEnd;$i++) {
                            // $rowProduct = $arrSaveCategory[$i]; //Получаем данные из массива.

                            $category_id = $arrSaveCategory[$i]['category_id'];
                            $category_parent = $arrSaveCategory[$i]['category_parent'];
                            $iParrentCatSave = $category_parent;
                            $category_name = $arrSaveCategory[$i]['category_name'];
                            $category_product_count = $arrSaveCategory[$i]['category_product_count'];
                            $category_price_min = $arrSaveCategory[$i]['category_price_min'];
                            $category_price_max = $arrSaveCategory[$i]['category_price_max'];

                            if($category_product_count <= 0 || $category_product_count == NULL)
                                $arrAllProduct[$Iterator++][0] =array('text' => $category_name, 'callback_data' => "all_product 0 0 $category_id -1");
                            else
                                $arrAllProduct[$Iterator++][0] =array('text' => $category_name, 'callback_data' => "product_info $category_id 0 none");
                        }
                    }
                }


                $strMenuName = "Категории (" . (($iPage+1) ? ($iPage+1):"1") .  "/" . $iAllPage . "):";// $message";


                //Или пересоздаём меню или редактируем имеющееся
                if($arrData['callback_query']['message']['message_id'] > 0) {


                    $dataSend = array(
                        'text' => $strMenuName,
                        'message_id' => $iMessageId,
                        'message_id' => $arrData['callback_query']['message']['message_id'],
                        'disable_web_page_preview' => true,
                        'parse_mode' => 'html',
                        'chat_id' => $chat_id
                    );

                    $this->requestToTelegram($dataSend, "editMessageText");
                } else {

                    $dataSend = array(
                        'text' => $strMenuName,
                        'message_id' => $iMessageId,
                        'disable_web_page_preview' => true,
                        'parse_mode' => 'html',
                        'chat_id' => $chat_id
                    );

                    $this->requestToTelegram($dataSend, "sendMessage");
                }

                $strBack = "all_product " . ($iPage - 1) . " $iAllPage $iCurrCategory -1";

                if($iPage+1 <= 0)
                    $iPage = 0;

                $strForward = "all_product " . ($iPage + 1) . " $iAllPage $iCurrCategory -1";


                if($iCurrCategory == -1) {
                    if($users_admin  == 1) {
                        $arrAllProduct[$Iterator++] = array(array('text' => '✎[Добавить категорию]', 'callback_data' => "add_category $iParrentCatSave"));

                        if(preg_match('/^remove_category/', $command_text) ? true : false) { //Для возможности вставить категорию в главное меню.
                            $arrAllProduct[$Iterator++] = array(
                                array('text' => '✎[Вставить категорию]', 'callback_data' => "paste_category $command_int_parameter -1 | $message") //Вставляем категорию и её ид в новую категори.
                            );
                        }
                    }
                    $arrAllProduct[$Iterator++] = array(array('text' => '←', 'callback_data' => $strBack),array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $arrData['callback_query']['message']['message_id']),array('text' => '→', 'callback_data' => $strForward));
                } else { //Если это подменю с категорией.
                    if($users_admin  == 1) {
                        if($iCountRows <= 0) {
                            $arrAllProduct[$Iterator++] = array(array('text' => '✎[Не совмещенная продукция]', 'callback_data' => "uncategorized_products 0 0 $iSaveCurrCategory none"));
                        }

                        if(preg_match('/^remove_category/', $command_text) ? true : false) {
                            $arrAllProduct[$Iterator++] = array(
                                array('text' => '✎[Добавить категорию]', 'callback_data' => "add_category $iParrentCatSave"),
                                array('text' => '✎[Переименовать родительскую категорию]', 'callback_data' => "rename_category $iParrentCatSave"),
                                array('text' => '✎[Вставить категорию]', 'callback_data' => "paste_category $command_int_parameter $iParrentCatSave | $message"), //Вставляем категорию и её ид в новую категори.
                                array('text' => '✎[Очистить категорию]', 'callback_data' => "clear_category $iParrentCatSave"),
                                array('text' => '✎[Удалить родительскую категорию]', 'callback_data' => "delete_category $iParrentCatSave"));
                        } else {
                            $arrAllProduct[$Iterator++] = array(
                                array('text' => '✎[Добавить категорию]', 'callback_data' => "add_category $iParrentCatSave"),
                                array('text' => '✎[Переименовать родительскую категорию]', 'callback_data' => "rename_category $iParrentCatSave"),
                                array('text' => '✎[Переместить категорию]', 'callback_data' => "remove_category $iParrentCatSave | $message"),
                                array('text' => '✎[Очистить категорию]', 'callback_data' => "clear_category $iParrentCatSave"),
                                array('text' => '✎[Удалить родительскую категорию]', 'callback_data' => "delete_category $iParrentCatSave"));
                        }
                    }
                    $arrAllProduct[$Iterator++] = array(array('text' => 'Назад', 'callback_data' => "all_product 0 0 $iParrentCatSave 1")); //5 параметр 1 - значит подымаемся на уровень выше.
                    $arrAllProduct[$Iterator++] = array(array('text' => '←', 'callback_data' => $strBack),array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $arrData['callback_query']['message']['message_id']),array('text' => '→', 'callback_data' => $strForward));
                }


                $inlineKeyboardAllCategory = $this->getInlineKeyBoard($arrAllProduct);

                $dataSend = array(
                    'reply_markup' => $inlineKeyboardAllCategory,
                    'message_id' => $iMessageId,
                    'chat_id' => $chat_id
                );

                //Редактируем сообщение что бы не засорять чат! Важно!
                $this->requestToTelegram($dataSend, "editMessageReplyMarkup");
                break;
            }
            case (preg_match('/^product_param/', $message) ? true : false): { //Здесь у нас возможность просмотреть параметры продукта, это нужно для копирования названия для создания продукта.
                $params = explode("|", $message);
                // product_param|$iPage $iAllPage $iParrCategory $strParrentMenu $product_id
                $nextParam = explode(" ", $params[1]); //Мы делим 2 раза что бы вытащить ид.

                $iProductId = $nextParam[4]; //Здесь передаётся из меню выше ид товара


                $iMessageId = $arrData['callback_query']['message']['message_id'];

                $query = "SELECT * FROM sp_product WHERE product_id=$iProductId;";


                $strInfoCategory = "";
                $product_name = ""; //Для сохранения имени.


                //Здесь мы получаем товары которые мы получили записи
                $resProduct = mysql_query($query);
                $rowProduct = mysql_fetch_array($resProduct);
                mysql_free_result($res);
                if($rowProduct) {

                    $product_name = $rowProduct['product_name'];
                    $product_link = $rowProduct['product_link'];
                    $product_time_get = $rowProduct['product_time_get'];
                    $product_price = $rowProduct['product_price'];

                    //Наименование  (товара)
                    //Дата обновления

                    //Цена  (ссылка)

                    $arrTimeUp = getdate($product_time_get);

                    $strInfoCategory = "Продукт:
					<b>Название:</b> $product_name\r
					<b>Обновлено:  [" . ($arrTimeUp['mday'] > 9 ? $arrTimeUp['mday']:"0".$arrTimeUp['mday']) . "." . ($arrTimeUp['mon'] > 9 ? $arrTimeUp['mon']:"0".$arrTimeUp['mon']) . "." . $arrTimeUp['year'] . "]</b>
					
					<b>Цена :</b> <a href='$product_link'>$product_price</a>
					";
                }

                //Редактируем заголовок меню
                $dataSend = array(
                    'text' => $strInfoCategory,
                    'message_id' => $iMessageId,
                    'disable_web_page_preview' => true,
                    'parse_mode' => 'html',
                    'chat_id' => $chat_id
                );

                $this->requestToTelegram($dataSend, "editMessageText");


                //Добавляем кнопки для управления
                $arrAllProduct;
                $Iterator = 0;


                $arrAllProduct[$Iterator++] = array(array('text' => 'Назад', 'callback_data' => "uncategorized_products " . $params[1]));
                $arrAllProduct[$Iterator++] = array(array('text' => 'Запомнить этот продукт', 'callback_data' => "uncategorized_products " . $params[1] . " save"));  //Кнопка позволяет сократить поиск до этого товара или до товаров содержащих эти же ключевые слова (или больше)
                $arrAllProduct[$Iterator++] = array(array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $iMessageId));

                $inlineKeyboardAllCategory = $this->getInlineKeyBoard($arrAllProduct);

                $dataSend = array(
                    'reply_markup' => $inlineKeyboardAllCategory,
                    'message_id' => $iMessageId,
                    'chat_id' => $chat_id
                );
                $this->requestToTelegram($dataSend, "editMessageReplyMarkup");


                break;
            }
            case (preg_match('/^product_info/', $message) ? true : false): {
                $params = explode(" ", $message);

                $iCategoryId = $params[1]; //Здесь передаётся из меню выше категория в которой мы ищем уже вложенные продукты.
                $iInFavorite = $params[2]; //Добавить убрать из избранного добавить 1, убрать -1.
                $strMyCategory = $params[3]; //Пометка если перешли из меню с избранными (что бы в него обратно вернуться)
                $strShowAll = $params[4]; //Если нажали показать все предложения.

                $iMessageId;

                if($arrData['callback_query']['message']['message_id'] > 0) {
                    $iMessageId = $arrData['callback_query']['message']['message_id'];
                } else {
                    $iMessageId = $arrData['message']['message_id']+2;
                }

                $arrAllProduct; //Для сохранения всех предложений (на случай если нужно вывести все предложения)
                $iCountProduct = 0; //Счетчик продуктов для отображения.

                if(!$errorSelect && !$errorConnect) {

                    $query = "SELECT * FROM sp_category WHERE category_id=$iCategoryId;";

                    //Здесь мы получаем товары которые мы получили записи
                    $resInfo = mysql_query($query) or $ErrorQuery = 1;

                    if(!$ErrorQuery) {
                        $rowSelect = mysql_fetch_array($resInfo);
                        if($rowSelect) {
                            $category_id = $rowSelect['category_id'];
                            $category_name = $rowSelect['category_name'];
                            $category_parent = $rowSelect['category_parent'];
                            $category_doughter = $rowSelect['category_doughter'];
                            $category_folowers = $rowSelect['category_folowers'];
                            $category_last_up = $rowSelect['category_last_up'];
                            $category_product_count = $rowSelect['category_product_count'];
                            $category_price_min = $rowSelect['category_price_min'];
                            $category_price_max = $rowSelect['category_price_max'];


                            $query = "SELECT * FROM sp_product WHERE product_category_id=$iCategoryId ORDER BY product_price;";

                            $strLinkMinPrice = "";
                            $strLinkMaxPrice = "";


                            //Здесь мы получаем товары которые мы получили записи
                            $resProduct = mysql_query($query) or $ErrorQuery = 1;
                            if(!$ErrorQuery) {

                                while($rowProduct = mysql_fetch_array($resProduct)) {

                                    $arrAllProduct[$iCountProduct] = $rowProduct;
                                    $iCountProduct++;


                                    $product_id = $rowProduct['product_id'];
                                    $product_name = $rowProduct['product_name'];
                                    $product_link = $rowProduct['product_link'];
                                    $product_time_get = $rowProduct['product_time_get'];
                                    $product_price = $rowProduct['product_price'];

                                    if($category_price_min == $product_price) {
                                        $strLinkMinPrice = $product_link;
                                    }

                                    if($category_price_max == $product_price) {
                                        $strLinkMaxPrice = $product_link;
                                    }
                                }
                            }
                            mysql_free_result($res);
                            // $this->setFileLog($arrAllProduct);


                            $iFolowCategory = 0; //Пометка что вы являетесь подписчиком этого товара!


                            if($iInFavorite == 1) {
                                $query = "INSERT INTO sp_folowers (folowers_users_id, folowers_category_id, folowers_timestamp) values ('$users_id', '$iCategoryId','$iTimestamp');";
                                mysql_query($query);
                                $category_folowers++;

                                $query = "UPDATE sp_category SET category_folowers='$category_folowers' WHERE category_id=$iCategoryId;;";
                                mysql_query($query);

                            } else if($iInFavorite == -1) {
                                $query = "DELETE FROM sp_folowers WHERE folowers_users_id='$users_id' AND folowers_category_id='$iCategoryId';";
                                mysql_query($query);
                                $category_folowers--;

                                $query = "UPDATE sp_category SET category_folowers='$category_folowers' WHERE category_id=$iCategoryId;;";
                                mysql_query($query);
                            }




                            $query = "SELECT * FROM sp_folowers WHERE folowers_category_id=$iCategoryId AND folowers_users_id=$users_id;";
                            //Здесь мы получаем товары которые мы получили записи
                            $resProduct = mysql_query($query) or $ErrorQuery = 1;
                            if(!$ErrorQuery) {
                                $iFolowCategory = mysql_num_rows($resProduct);
                            }

                            if($category_folowers == NULL)
                                $category_folowers = 0;

                            //Наименование категории (товара)
                            //Цена минимум (ссылка)
                            //Цена максимум (ссылка)
                            //Количество магазинов с товаром - число.


                            $arrTimeUp = getdate($category_last_up);

                            $strInfoCategory = "Продукт:
							<b>Название:</b> $category_name\r
							<b>Подписчиков:</b> $category_folowers\r
							<b>Представлено:</b> $category_product_count\r
							<b>Обновлено:  [" . ($arrTimeUp['mday'] > 9 ? $arrTimeUp['mday']:"0".$arrTimeUp['mday']) . "." . ($arrTimeUp['mon'] > 9 ? $arrTimeUp['mon']:"0".$arrTimeUp['mon']) . "." . $arrTimeUp['year'] . "]</b>\r
							
							<b>Цена минимум:</b> <a href='$strLinkMinPrice'>$category_price_min</a>\r
							<b>Цена максимум:</b> <a href='$strLinkMaxPrice'>$category_price_max</a>\r

							  ";

                            if(preg_match('/^all/', $strShowAll)) {
                                for($i=0;$i<$iCountProduct;$i++) {

                                    $strLink = $arrAllProduct[$i]['product_link'];
                                    $strLink = trim($strLink);

                                    $strDel = array("https://www.","http://www.","https://","http://","www.");
                                    $iCount = count($strDel);
                                    for($j=0;$j<$iCount;$j++) {
                                        $strLink = str_replace($strDel[$j],'',$strLink);
                                    }
                                    // $strLink = trim($strLink);

                                    $strLink = explode("/", $strLink);
                                    // $strLink = $arrAllProduct[$i]['product_link'];
                                    $strInfoCategory = $strInfoCategory . "$i) <a href='" . $arrAllProduct[$i]['product_link'] . "'>" . trim($strLink[0]) ." " . $arrAllProduct[$i]['product_price'] . "</a>\r
									";
                                }
                            }

                            //Редактируем заголовок меню
                            if($arrData['callback_query']['message']['message_id'] > 0) {


                                $dataSend = array(
                                    'text' => $strInfoCategory,
                                    'message_id' => $iMessageId,
                                    'message_id' => $arrData['callback_query']['message']['message_id'],
                                    'disable_web_page_preview' => true,
                                    'parse_mode' => 'html',
                                    'chat_id' => $chat_id
                                );

                                $this->requestToTelegram($dataSend, "editMessageText");
                            } else {

                                $dataSend = array(
                                    'text' => $strInfoCategory,
                                    'message_id' => $iMessageId,
                                    'disable_web_page_preview' => true,
                                    'parse_mode' => 'html',
                                    'chat_id' => $chat_id
                                );

                                $this->requestToTelegram($dataSend, "sendMessage");
                            }

                            //Добавляем кнопки для управления
                            $arrAllProduct;
                            $Iterator = 0;

                            if(!preg_match('/^all/', $strShowAll))
                                $arrAllProduct[$Iterator++] = array(array('text' => 'Просмотреть все предложения', 'callback_data' => "product_info $iCategoryId 0 $strMyCategory all"));

                            if($iFolowCategory)
                                $arrAllProduct[$Iterator++] = array(array('text' => 'Из избранного', 'callback_data' => "product_info $iCategoryId -1 $strMyCategory $strShowAll none"));
                            else {
                                $arrAllProduct[$Iterator++] = array(array('text' => 'В избранное', 'callback_data' => "product_info $iCategoryId 1 $strMyCategory $strShowAll none"));
                            }

                            $arrAllProduct[$Iterator++] = array(array('text' => 'Нашли ошибку/Оставить отзыв', 'callback_data' => "input_review $iCategoryId $strMyCategory"));

                            if($users_admin) {
                                $arrAllProduct[$Iterator++] = array(array('text' => '✎[Удалить совмещение]', 'callback_data' => "delcategorized_products 0 0 $iCategoryId $strMyCategory"));
                                $arrAllProduct[$Iterator++] = array(array('text' => '✎[Не совмещенная продукция]', 'callback_data' => "uncategorized_products 0 0 $iCategoryId $strMyCategory"));
                            }


                            if(preg_match('/^my_product/', $strMyCategory) )
                                $arrAllProduct[$Iterator++] = array(array('text' => 'Назад', 'callback_data' => "my_product 0 0 $iCategoryId 1")); //5 параметр 1 - значит подымаемся на уровень выше.
                            else if(preg_match('/^quick_search/', $strMyCategory) )
                                $arrAllProduct[$Iterator++] = array(array('text' => 'Назад', 'callback_data' => "quick_search 0 0 $iCategoryId 1")); //5 параметр 1 - значит подымаемся на уровень выше.
                            else
                                $arrAllProduct[$Iterator++] = array(array('text' => 'Назад', 'callback_data' => "all_product 0 0 $iCategoryId 1")); //5 параметр 1 - значит подымаемся на уровень выше.



                            $arrAllProduct[$Iterator++] = array(array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $iMessageId));

                            $inlineKeyboardAllCategory = $this->getInlineKeyBoard($arrAllProduct);

                            $dataSend = array(
                                'reply_markup' => $inlineKeyboardAllCategory,
                                'message_id' => $iMessageId,
                                'chat_id' => $chat_id
                            );

                            //Редактируем сообщение что бы не засорять чат! Важно!
                            $this->requestToTelegram($dataSend, "editMessageReplyMarkup");




                        }
                        mysql_free_result($resInfo);
                    }
                }
                break;
            }
            case (preg_match('/^my_product/', $message) ? true : false): {
                $params = explode(" ", $message);

                $iCurrCategory = $params[3];
                $iParentCategory = $params[4]; //Если -1 значит не чего не делаем, если 1 значит ищем куда вложена родительская категория.
                $iSaveCurrCategory = $iCurrCategory; //Сохраняем родительскую категорию, что бы понимать


                $iAllPage = 0; //Сколько всего страниц
                $iPage = 0; //Страница
                $iCountOnPage = $arrSettings[0]['param']; //Здесь количество записей на странице (будет браться из настроек)
                $iCountRows = 0; //Здесь будет общее количество записей
                $iIterator = 0; //Это будет счетчик цикла

                $iStart = 0; //Начальная с которой считать на странице
                $iEnd = 0; //Конечная до которой отображаем на странице




                $arrSaveCategory;
                if(!$errorSelect && !$errorConnect) {
                    $query = "SELECT * FROM sp_folowers WHERE folowers_users_id='$users_id';";
                    //Здесь мы получаем товары которые мы получили записи
                    $resInfo = mysql_query($query) or $ErrorQuery = 1;
                    if(!$ErrorQuery) {
                        $iCountRows = mysql_num_rows($resInfo); // 6

                        $iCounterElem = 0;
                        while($rowProduct = mysql_fetch_array($resInfo)) {
                            $arrSaveCategory[$iCounterElem] = $rowProduct;
                            $iCounterElem++;
                        }
                        mysql_free_result($resInfo);

                        if($iParentCategory == 1 && $iSaveCurrCategory >= 0) { //Если пользователь хочет подняться выше на категорию расчитываем на какой странице показать меню.

                            $iCount = count($arrSaveCategory);
                            for($i = 0;$i<$iCount;$i++) {
                                if($arrSaveCategory[$i]['folowers_category_id'] == $iSaveCurrCategory) {
                                    $iPage = $i / $iCountOnPage;
                                    break;
                                }
                            }
                        } else {
                            //Если страница задана и из скольки задана получаем их.
                            if($params[1] >= 0) {
                                $iPage = $params[1];
                            }
                            if($params[2] > 0)
                                $iAllPage = $params[2];
                        }

                        if($iParentCategory != 1) {
                            if($iPage >= $iAllPage)
                                $iPage = $iAllPage - 1;
                        }


                        if($iPage < 0)
                            $iPage = 0;

                    }



                    $arrAllProduct;
                    $Iterator = 0;



                    //Здесь будет расчитывать сколько у нас страниц и какая сейчас из скольки.

                    //   4      = мин ( 2    *   2          , 6          )
                    $iIterator = min($iPage * $iCountOnPage, $iCountRows); // 2

                    //   2   =      4     - (    4       %     2        )
                    $iStart = $iIterator - ($iIterator % $iCountOnPage);

                    //  4   =       2    +     2      ,      6
                    $iEnd = min($iStart + $iCountOnPage, $iCountRows);
                    //				2		/      2
                    $iPage = floor($iStart / $iCountOnPage);

                    $iAllPage = floor((($iCountRows - 1) / $iCountOnPage) + 1);


                    $iCount = count($arrSaveCategory);
                    $iEnd = min($iEnd,$iCount);
                    for($iIterator = $iStart;$iIterator<$iEnd;$iIterator++) {

                        $folowers_category_id = $arrSaveCategory[$iIterator]['folowers_category_id'];
                        $query = "SELECT * FROM sp_category WHERE category_id='$folowers_category_id';";

                        $resCategory = mysql_query($query) or $ErrorQuery = 1;

                        if(!$ErrorQuery) {
                            $rowSelect = mysql_fetch_array($resCategory);
                            mysql_free_result($resCategory);
                            if($rowSelect) {
                                $category_id = $rowSelect['category_id'];
                                $category_name = $rowSelect['category_name'];
                                $category_folowers = $rowSelect['category_folowers'];
                                $category_product_count = $rowSelect['category_product_count'];


                                if($category_product_count >= 0 && $category_product_count != NULL)
                                    $arrAllProduct[$Iterator++][0] =array('text' => $category_name, 'callback_data' => "product_info $category_id 0 my_product");
                            }
                        }
                    }
                }




                //Код ниже по идее уже готов!
                $strMenuName = "Моё избранное $iCountRows (" . (($iPage+1) ? ($iPage+1):"1") .  "/" . $iAllPage . "):";// $message";

                $dataSend = array(
                    'text' => $strMenuName,
                    'message_id' => $arrData['callback_query']['message']['message_id'],
                    'chat_id' => $chat_id
                );
                $this->requestToTelegram($dataSend, "editMessageText");



                $strBack = "my_product " . ($iPage - 1) . " $iAllPage";

                if($iPage+1 <= 0)
                    $iPage = 0;

                $strForward = "my_product " . ($iPage + 1) . " $iAllPage";
                $arrAllProduct[$Iterator++] = array(array('text' => '←', 'callback_data' => $strBack),array('text' => 'Главное меню', 'callback_data' => 'main_menu ' . $arrData['callback_query']['message']['message_id']),array('text' => '→', 'callback_data' => $strForward));



                $inlineKeyboardAllCategory = $this->getInlineKeyBoard($arrAllProduct);

                $dataSend = array(
                    'reply_markup' => $inlineKeyboardAllCategory,
                    'message_id' => $arrData['callback_query']['message']['message_id'],
                    'chat_id' => $chat_id
                );

                //Редактируем сообщение что бы не засорять чат! Важно!
                $this->requestToTelegram($dataSend, "editMessageReplyMarkup");
                break;
            }
            default: {
                $dataSend = array(
                    'text' => "<b>Непредвиденное<b> действие. " . $message,
                    'chat_id' => $chat_id,
                );
                $this->requestToTelegram($dataSend, "sendMessage");
                break;
            }
        }
    }

    private function info($data,$text, $callback_query_id) {
        /** Меняем клавиатуру Vote
         * @param $data
         * @param $emogi
         * @param $callback_query_id
         */

        $this->requestToTelegram([
            'callback_query_id' => $callback_query_id,
            'text' => $text,
            'cache_time' => 30,
        ], "answerCallbackQuery");
    }
    private function getInlineKeyBoard($data) {
        /**
         * создаем inline клавиатуру
         * @return string
         */
        $inlineKeyboard = array(
            "inline_keyboard" => $data,
        );
        return json_encode($inlineKeyboard);
    }
    private function getKeyBoard($data) {
        /**
         * создаем клавиатуру
         * @return string
         */
        $keyboard = array(
            "keyboard" => $data,
            "one_time_keyboard" => false,
            "resize_keyboard" => true
        );
        return json_encode($keyboard);
    }
    private function setFileLog($data) {
        $fh = fopen('logbot.txt', 'a') or die('can\'t open file');
        ((is_array($data)) || (is_object($data))) ? fwrite($fh, print_r($data, TRUE) . "\n") : fwrite($fh, $data . "\n");
        fclose($fh);
    }
    private function getData($data) {
        /**
         * Парсим что приходит преобразуем в массив
         * @param $data
         * @return mixed
         */
        return json_decode(file_get_contents($data), TRUE);
    }
    private function requestToTelegram($data, $type) {
        /** Отправляем запрос в Телеграмм
         * @param $data
         * @param string $type
         * @return mixed
         */
        $result = null;

        if (is_array($data)) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->apiUrl . $this->botToken . '/' . $type);
            curl_setopt($ch, CURLOPT_POST, count($data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $result = curl_exec($ch);
            curl_close($ch);
        }
        return $result;
    }
}
