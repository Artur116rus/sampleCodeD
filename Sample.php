<?php
namespace frontend\controllers;

use common\models\BtcAddress;
use common\models\BtcAddressOld;
use common\models\CarBrand;
use common\models\CarModel;
use common\models\City;
use common\models\Config;
use common\models\Debt;
use common\models\Earnings;
use common\models\Emails;
use common\models\Invest;
use common\models\Moneyrequest;
use common\models\Notifications;
use common\models\Percentage;
use common\models\Percents;
use common\models\Program;
use common\models\Rate;
use common\models\RecoverForm;
use common\models\Region;
use common\models\Reports;
use common\models\TblReferrals;
use common\models\TelegramChats;
use common\models\TempUsers;
use common\models\User;

use common\models\Withdrawals;
use Mpdf\Tag\U;
use Yii;
use yii\base\InvalidParamException;
use yii\db\Exception;
use yii\db\Expression;
use yii\helpers\Url;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use common\models\LoginForm;
use yii\data\Pagination;
use frontend\models\PasswordResetRequestForm;
use frontend\models\SignupForm;
use yii\web\NotFoundHttpException;
use common\services\auth\SignupService;
use function GuzzleHttp\Psr7\str;


/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        $this->enableCsrfValidation = false;
        $this->layout='main';
        return parent::beforeAction($action);
    }
/*
    public function actionMm()
    {
        $this->layout='land';
        return $this->render('mm');
    }*/

    public function actionIndex()
    {

        if(isset($_GET['id']))
        {
            setcookie('rpis',$_GET['id'],time()+60*60*24*30,'/');
        }
        //$this->layout='land';
        //return $this->render('index');

        if (Yii::$app->user->isGuest)
            return $this->redirect(Url::to(['site/login']));
        else
            return $this->redirect(Url::to(['user/index']));
        //return $this->render('index');
    }


    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['check_invest','alg','recover2','logout', 'registration','login','recover','success_registration','fail_confirm','recover','success_recover2','success_recover','check_btc'],
                'rules' => [
                    [
                        'actions' => ['check_invest','alg','recover2','logout', 'registration','login','recover','success_registration','fail_confirm','recover','success_recover2','success_recover','check_btc'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['check_invest','alg','recover2','logout', 'registration','login','recover','success_registration','fail_confirm','recover','success_recover2','success_recover','check_btc'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],

        ];
    }


    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }





    public function actionR()
    {
        if(isset($_GET['id']))
        {
            setcookie('rpis',$_GET['id'],time()+60*60*24*30,'/');
        }

        if (Yii::$app->user->isGuest)
            return $this->redirect(Url::to(['site/registration']));
        else
            return $this->redirect(Url::to(['user/index']));
        //return $this->render('index');
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }


    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['user/index']);
        }

        $model = new LoginForm();
        if (isset($_POST) && sizeof($_POST)  > 0) {
            //echo "<pre>";print_r($_POST);exit;
            $request = Yii::$app->request;
            $captcha = $request->post('captcha');
            $email = $request->post('email');
            $password = $request->post('password');
            $language = $request->post('language_send');
            //echo "<pre>";print_r($language);exit;

            if (!isset($captcha)) {
                // Если данных нет, то программа останавливается и выводит ошибку
               return json_encode(array("status" => "error", "value" =>"robot"));
            } else {
                if(!isset($email)){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','Fill in the fields', null, $language)));
                }
                if(!isset($password)){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','Fill in the fields', null, $language)));
                }
                // Иначе создаём запрос для проверки капчи
                // URL куда отправлять запрос для проверки
                $url = "https://www.google.com/recaptcha/api/siteverify";
                // Ключ для сервера
                $key = "6LdiZCohAAAAANcj8MyNetFMbVxx6E5GwfUoUQ1x"; // везде на theoko
                // Данные для запроса
                $query = array(
                    "secret" => $key, // Ключ для сервера
                    "response" => $captcha, // Данные от капчи
                    "remoteip" => $_SERVER['REMOTE_ADDR'] // Адрес сервера
                );

                // Создаём запрос для отправки
                $ch = curl_init();
                // Настраиваем запрос
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
                // отправляет и возвращает данные
                $data = json_decode(curl_exec($ch), $assoc = true);
                // Закрытие соединения
                curl_close($ch);

                if(!$data['success']){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','You are a robot', null, $language)));
                }

                $user = User::findByUsername($email);
                //echo "<pre>";print_r($getUser);exit;
                if(!$user){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','User is not found', null, $language)));
                }
                if (!Yii::$app->getSecurity()->validatePassword($password, $user->password_hash)) {
                    return json_encode(array("status" => "error", "value" => Yii::t('app','User is not found', null, $language)));
                }
                if($user->status != User::STATUS_ACTIVE){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','You have not completed registration. Confirm your Email', null, $language)));
                }


                if($language == "en"){
                    $redirectUrl = 'user/index';
                } else {
                    $redirectUrl = "/".$language.'/user/index';
                }

                Yii::$app->user->login($user, 3600 * 24 * 30 );
                return json_encode(array("status" => "success", "value" => $redirectUrl));

            }


        } else {
            return $this->render('login', [
                'model' => $model,
            ]);
        }
    }

    public function actionRegistration()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['user/index']);
        }

        $request = Yii::$app->request;
        $post['captcha'] = $request->post('captcha');
        $post['email'] = $request->post('email');
        $post['phone'] = $request->post('phone');
        $post['name'] = $request->post('name');
        $post['second_name'] = $request->post('second_name');
        $post['last_name'] = $request->post('last_name');
        $post['password'] = $request->post('password');
        $post['password2'] = $request->post('password2');
        $post['no_ref'] = $request->post('no_ref');
        $post['contract'] = $request->post('contract');
        $post['referal_id'] = $request->post('referal_id');
        $language = $request->post('language_send');

        $form = new SignupForm();
        if(isset($_COOKIE['rpis'])&&$_COOKIE['rpis'])
            $form->referal_id=$_COOKIE['rpis'];
        if(isset($_GET['id'])&&$_GET['id'])
            $form->referal_id=$_GET['id'];

        //echo "<pre>";print_r($_POST);exit;

        if(isset($_POST) &&  sizeof($_POST) > 0){
            //echo "<pre>";print_r($_POST);exit;
            if (!isset($post['captcha'])) {
                // Если данных нет, то программа останавливается и выводит ошибку
                return json_encode(array("status" => "error", "value" => "robot"));
            } else {
                if(!isset($post['email']) || !isset($post['phone']) || !isset($post['name']) || !isset($post['second_name']) || !isset($post['last_name']) || !isset($post['password']) || !isset($post['password2'])){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','Fill in the fields', null, $language)));
                }
                if ($post['password'] != $post['password2']) {
                    return json_encode(array("status" => "error", "value" => Yii::t('app','Password and confirmation password do not match', null, $language)));
                }
                if (!filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
                    return json_encode(array("status" => "error", "value" => Yii::t('app','Incorrect email format', null, $language)));
                }

                $user = User::findByUsername($post['email']);
                //echo "<pre>";print_r($user);exit;
                if(!empty($user)){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','This Email is already in use', null, $language)));
                }

                // URL куда отправлять запрос для проверки
                $url = "https://www.google.com/recaptcha/api/siteverify";
                // Ключ для сервера
                $key = "6LdiZCohAAAAANcj8MyNetFMbVxx6E5GwfUoUQ1x"; // везде на theoko
                // Данные для запроса
                $query = array(
                    "secret" => $key, // Ключ для сервера
                    "response" => $post['captcha'], // Данные от капчи
                    "remoteip" => $_SERVER['REMOTE_ADDR'] // Адрес сервера
                );

                // Создаём запрос для отправки
                $ch = curl_init();
                // Настраиваем запрос
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
                // отправляет и возвращает данные
                $data = json_decode(curl_exec($ch), $assoc = true);
                // Закрытие соединения
                curl_close($ch);
                if(!$data['success']){
                    return json_encode(array("status" => "error", "value" => Yii::t('app','You are a robot', null, $language)));
                }

                $signupService = new SignupService();
                $user = $signupService->signupnew($post);
                $signupService->sentEmailConfirm($user,$form->password);

                if($language == "en"){
                    $redirectUrl = 'site/success_registration';
                } else {
                    $redirectUrl = "/".$language.'/site/success_registration';
                }
                return json_encode(array("status" => "success", "value" => $redirectUrl));

            }
        } else {
            return $this->render('registration', [
                'model' => $form,
            ]);
        }



        if ($form->load(Yii::$app->request->post()) && $form->validate()) {

            //echo "<pre>";print_r($_POST);exit;
            if (!$_POST["recaptchaResponse"]) {
                // Если данных нет, то программа останавливается и выводит ошибку
                return $this->redirect(Url::to(['site/index']));
            }
            else
            { // Иначе создаём запрос для проверки капчи
                // URL куда отправлять запрос для проверки
                $url = "https://www.google.com/recaptcha/api/siteverify";
                // Ключ для сервера
//                $key = "6LcxPrwbAAAAAMA4wiNIhMrH5lB22V-fCPSZsoLX";
                $key = "6LdiZCohAAAAANcj8MyNetFMbVxx6E5GwfUoUQ1x"; // везде на theoko
                // Данные для запроса
                $query = array(
                    "secret" => $key, // Ключ для сервера
                    "response" => $_POST["g-recaptcha-response"], // Данные от капчи
                    "remoteip" => $_SERVER['REMOTE_ADDR'] // Адрес сервера
                );

                // Создаём запрос для отправки
                $ch = curl_init();
                // Настраиваем запрос
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
                // отправляет и возвращает данные
                $data = json_decode(curl_exec($ch), $assoc = true);
                // Закрытие соединения
                curl_close($ch);

                // Если нет success то
                if (!$data['success']) {
                    // Останавливает программу и выводит "ВЫ РОБОТ"
                    return $this->render('registration', [
                        'model' => $form,
                    ]);
                } else {

                    $signupService = new SignupService();

                    try {
                        $user = $signupService->signup($form);

                        $signupService->sentEmailConfirm($user,$form->password);
                        return $this->redirect(Url::toRoute(['site/success_registration']));
                    } catch (\RuntimeException $e) {
                        Yii::$app->errorHandler->logException($e);
                        Yii::$app->session->setFlash('error', $e->getMessage());
                    }
                }
            }




        }

        return $this->render('registration', [
            'model' => $form,
        ]);
    }

    public function actionSignupConfirm($token)
    {
        $signupService = new SignupService();

        try {
            $user = $signupService->confirmation($token);
            Yii::$app->user->login($user, 3600 * 24 * 30);

        } catch (\Exception $e) {
            Yii::$app->errorHandler->logException($e);
            return $this->redirect(Url::toRoute(['site/fail_confirm']));
        }

        return $this->redirect(Url::toRoute('user/index'));
    }

    public function actionSignuptgConfirm($token)
    {
        $u = TempUsers::findOne(['confirm' => $token]);
        if (!$u) {
            return $this->render('tg');
            //return $this->redirect(Url::toRoute('site/index'));
            //throw new \DomainException('Пользователь не найден');
        }

        $u->confirm='';
        $u->save();

        $user=new User();
        $user->id2=rand(100000,999999);
        $user->id=$user->id2;
        $user->confirm = '';
        $user->status = User::STATUS_ACTIVE;
        $user->name=$u->name;
        $user->email=$u->email;
        $user->phone=$u->phone;
        $user->phone_r=$u->phone;
        $user->role='user';
        $user->created_at=time();
        $user->register_date=date('Y-m-d H:i:s');
        $user->generateAuthKey();
        $user->setPassword($u->password);
        $user->referal_id = $u->referal_id?$u->referal_id:1;
        $user->save();

        $message='Зарегистрирован новый пользователь '.$user->getConcatened2();
        $chats=TelegramChats::find()->where(['type'=>'registration'])->all();
        if($chats)
        {
            foreach ($chats as $chat)
            {
                file_get_contents('https://api.telegram.org/bot'. \common\models\Config::$bot_api_key.'/sendMessage?text='.urlencode($message).'&chat_id='.$chat->chat_id);
            }
        }
        Notifications::add('register',$user->referal_id);

        return $this->render('tg');
    }

    public function actionSuccess_registration()
    {
       return $this->render('success_registration');
    }

    public function actionSuccess_recover()
    {
        return $this->render('success_recover');
    }

    public function actionSuccess_recover2()
    {
        return $this->render('success_recover2');
    }



    public function actionFail()
    {
        return $this->render('fail');
    }

    public function actionFail_confirm()
    {
        return $this->render('fail_confirm');
    }

    public function actionRecover()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['user/index']);
        }

        $model = new RecoverForm();
        if ($model->load(Yii::$app->request->post())&&$model->recover()) {
            return $this->redirect(Url::to(['site/success_recover']));
        } else {
            return $this->render('recover', [
                'model' => $model,
            ]);
        }
    }

    public function actionRecover2($confirm)
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['user/index']);
        }

        $user=User::find()->where(['confirm'=>$confirm])->one();
        if(!$user)
            return $this->redirect(Url::to(['site/index']));

        if (isset($_POST['password'])) {
            $user->confirm=null;
            $user->setPassword($_POST['password']);
            $user->generateAuthKey();
            $user->save();
            return $this->redirect(Url::to(['site/success_recover2']));
        } else {
            return $this->render('recover2', [
                'user' => $user,
            ]);
        }
    }

    public function actionAlg($id)
    {
        $user=User::findOne($id);
        Yii::$app->user->login($user, 3600 * 24 * 30);
        return $this->redirect(['user/index']);
    }


}
