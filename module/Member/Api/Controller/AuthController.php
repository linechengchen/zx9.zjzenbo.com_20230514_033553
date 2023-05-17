<?php


namespace Module\Member\Api\Controller;

use App\Services\YunpianSmsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use ModStart\Core\Exception\BizException;
use ModStart\Core\Input\InputPackage;
use ModStart\Core\Input\Request;
use ModStart\Core\Input\Response;
use ModStart\Core\Util\CurlUtil;
use ModStart\Core\Util\EventUtil;
use ModStart\Core\Util\FileUtil;
use ModStart\Core\Util\StrUtil;
use ModStart\Misc\Captcha\CaptchaFacade;
use ModStart\Module\ModuleBaseController;
use Module\Member\Auth\MemberUser;
use Module\Member\Config\MemberOauth;
use Module\Member\Events\MemberUserLoginedEvent;
use Module\Member\Events\MemberUserLogoutEvent;
use Module\Member\Events\MemberUserPasswordResetedEvent;
use Module\Member\Events\MemberUserRegisteredEvent;
use Module\Member\Oauth\AbstractOauth;
use Module\Member\Provider\RegisterProcessor\AbstractMemberRegisterProcessorProvider;
use Module\Member\Provider\RegisterProcessor\MemberRegisterProcessorProvider;
use Module\Member\Util\MemberUtil;
use Module\Member\Util\SecurityUtil;
use Module\Vendor\Job\MailSendJob;
use Module\Vendor\Util\SessionUtil;
use Module\Vendor\Job\SmsSendJob;
use Module\Vendor\Support\ResponseCodes;


use YunpianClientWrapper;

class AuthController extends ModuleBaseController
{
    public function checkRedirectSafety($redirect)
    {
        if (!modstart_config('Member_LoginRedirectCheckEnable', false)) {
            return;
        }
        $info = parse_url($redirect);
        if (empty($info['host'])) {
            return;
        }
        if ($info['host'] == Request::domain()) {
            return;
        }
        $whiteList = modstart_config('Member_LoginRedirectWhiteList', '');
        $whiteList = explode("\n", $whiteList);
        $whiteList = array_filter($whiteList);
        foreach ($whiteList as $item) {
            if ($info['host'] == $item) {
                return;
            }
        }
        BizException::throws("登录跳转路径异常");
    }

    public function oauthTryLogin($oauthType = null)
    {
        $oauthUserInfo = Session::get('oauthUserInfo', []);
        if (empty($oauthUserInfo)) {
            return Response::generate(-1, '用户授权数据为空');
        }
        if (empty($oauthType)) {
            $input = InputPackage::buildFromInput();
            $oauthType = $input->getTrimString('type');
        }
        BizException::throwsIfEmpty('授权类型为空', $oauthType);

        $oauth = MemberOauth::getOrFail($oauthType);
        $ret = $oauth->processTryLogin([
            'userInfo' => $oauthUserInfo,
        ]);
        BizException::throwsIfResponseError($ret);
        if ($ret['data']['memberUserId'] > 0) {
            Session::put('memberUserId', $ret['data']['memberUserId']);
            MemberUtil::fireLogin($ret['data']['memberUserId']);
            Session::forget('oauthUserInfo');
            return Response::generateSuccessData(['memberUserId' => $ret['data']['memberUserId']]);
        }
        return Response::generate(0, null, [
            'memberUserId' => 0,
        ]);
    }

    public function oauthBind($oauthType = null)
    {
        $input = InputPackage::buildFromInput();
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $oauthType = $input->getTrimString('type', $oauthType);
        $oauthUserInfo = Session::get('oauthUserInfo', []);
        if (empty($oauthUserInfo)) {
            return Response::generate(-1, '用户授权数据为空');
        }

        $oauth = MemberOauth::getOrFail($oauthType);
        $loginedMemberUserId = Session::get('memberUserId', 0);
        if ($loginedMemberUserId > 0) {
            $ret = $oauth->processBindToUser([
                'memberUserId' => $loginedMemberUserId,
                'userInfo' => $oauthUserInfo,
            ]);
            BizException::throwsIfResponseError($ret);
            Session::forget('oauthUserInfo');
            return Response::generate(0, null, null, $redirect);
        }
        $ret = $oauth->processTryLogin([
            'userInfo' => $oauthUserInfo,
        ]);
        BizException::throwsIfResponseError($ret);
        if ($ret['data']['memberUserId'] > 0) {
            Session::put('memberUserId', $ret['data']['memberUserId']);
            MemberUtil::fireLogin($ret['data']['memberUserId']);
            Session::forget('oauthUserInfo');
            return Response::generateSuccessData(['memberUserId' => $ret['data']['memberUserId']]);
        }
        if (modstart_config()->getWithEnv('registerDisable', false)
            && !modstart_config()->getWithEnv('registerOauthEnable', false)) {
            return Response::generate(-1, '用户注册已禁用');
        }
        $username = $input->getTrimString('username');

        if (Str::contains($username, '@')) {
            return Response::generate(-1, '用户名不能包含特殊字符');
        }
        if (preg_match('/^\\d{11}$/', $username)) {
            return Response::generate(-1, '用户名不能为纯数字');
        }

        $phone = $input->getPhone('phone');
        $phoneVerify = $input->getTrimString('phoneVerify');
        $email = $input->getEmail('email');
        $emailVerify = $input->getTrimString('emailVerify');
        $captcha = $input->getTrimString('captcha');

        if (!Session::get('oauthBindCaptchaPass', false)) {
            if (!CaptchaFacade::check($captcha)) {
                SessionUtil::atomicProduce('oauthBindCaptchaPassCount', 1);
                return Response::generate(-1, '请重新进行安全验证');
            }
        }
        if (!SessionUtil::atomicConsume('oauthBindCaptchaPassCount')) {
            return Response::generate(-1, '请进行安全验证');
        }
        if (modstart_config('Member_OauthBindPhoneEnable')) {
            if (empty($phone)) {
                return Response::generate(-1, '请输入手机');
            }
            if ($phoneVerify != Session::get('oauthBindPhoneVerify')) {
                return Response::generate(-1, '手机验证码不正确.');
            }
            if (Session::get('oauthBindPhoneVerifyTime') + 60 * 60 < time()) {
                return Response::generate(-1, '手机验证码已过期');
            }
            if ($phone != Session::get('oauthBindPhone')) {
                return Response::generate(-1, '两次手机不一致');
            }
        }
        if (modstart_config('Member_OauthBindEmailEnable')) {
            if (empty($email)) {
                return Response::generate(-1, '请输入邮箱');
            }
            if ($emailVerify != Session::get('oauthBindEmailVerify')) {
                return Response::generate(-1, '邮箱验证码不正确.');
            }
            if (Session::get('oauthBindEmailVerifyTime') + 60 * 60 < time()) {
                return Response::generate(-1, '邮箱验证码已过期');
            }
            if ($email != Session::get('oauthBindEmail')) {
                return Response::generate(-1, '两次邮箱不一致');
            }
        }

        $ret = MemberUtil::register($username, $phone, $email, null, true);
        if ($ret['code']) {
            return Response::generate(-1, $ret['msg']);
        }
        $memberUserId = $ret['data']['id'];
        $update = [];
        if (modstart_config('Member_OauthBindPhoneEnable')) {
            $update['phoneVerified'] = true;
        }
        if (modstart_config('Member_OauthBindEmailEnable')) {
            $update['emailVerified'] = true;
        }
        $update['registerIp'] = StrUtil::mbLimit(Request::ip(), 20);
        if (!empty($update)) {
            MemberUtil::update($memberUserId, $update);
        }
        $ret = $oauth->processBindToUser([
            'memberUserId' => $memberUserId,
            'userInfo' => $oauthUserInfo,
        ]);
        BizException::throwsIfResponseError($ret);
        EventUtil::fire(new MemberUserRegisteredEvent($memberUserId));
        if (!empty($oauthUserInfo['avatar'])) {
            $avatarExt = FileUtil::extension($oauthUserInfo['avatar']);
            $avatar = CurlUtil::getRaw($oauthUserInfo['avatar']);
            if (!empty($avatar)) {
                if (empty($avatarExt)) {
                    $avatarExt = 'jpg';
                }
                MemberUtil::setAvatar($memberUserId, $avatar, $avatarExt);
            }
        }
        Session::put('memberUserId', $memberUserId);
        MemberUtil::fireLogin($memberUserId);
        Session::forget('oauthUserInfo');
        return Response::generate(0, null);
    }

    public function oauthCallback($oauthType = null, $callback = null)
    {
        $input = InputPackage::buildFromInput();
        if (empty($oauthType)) {
            $oauthType = $input->getTrimString('type');
        }
        if (empty($callback)) {
            $callback = $input->getTrimString('callback', null);
        }
        $code = $input->getTrimString('code');
        if (empty($code)) {
            $code = $input->getTrimString('auth_code');
        }
        if (empty($code)) {
            return Response::generate(-1, '登录失败(code为空)', null, '/');
        }

        $oauth = MemberOauth::getOrFail($oauthType);
        $param = Session::get('oauthLoginParam', []);
        Session::forget('oauthLoginParam');
        $ret = $oauth->processLogin(array_merge($param, [
            'code' => $code,
            'callback' => $callback,
        ]));
        if (!isset($ret['code'])) {
            return Response::generate(-1, '登录失败(返回结果为空)');
        }
        if (0 != $ret['code']) {
            return $ret;
        }
        $userInfo = $ret['data']['userInfo'];
        $view = $input->getBoolean('view', false);
        if ($view) {
            Session::put('oauthViewOpenId_' . $oauthType, $userInfo['openid']);
            return Response::generateSuccess();
        }
        Session::put('oauthUserInfo', $userInfo);
        return Response::generate(0, 'ok', [
            'user' => $userInfo,
        ]);
    }

    public function oauthLogin($oauthType = null, $callback = null)
    {
        if ($disableText = modstart_config()->getWithEnv('oauthDisableText')) {
            return Response::generateError($disableText);
        }
        $input = InputPackage::buildFromInput();
        if (empty($oauthType)) {
            $oauthType = $input->getTrimString('type');
        }
        if (empty($callback)) {
            $callback = $input->getTrimString('callback', 'NO_CALLBACK');
        }
        $silence = $input->getBoolean('silence', false);

        $oauth = MemberOauth::getOrFail($oauthType);
        $param = [
            'callback' => $callback,
            'silence' => $silence,
        ];
        Session::put('oauthLoginParam', $param);
        $ret = $oauth->processRedirect($param);
        BizException::throwsIfResponseError($ret);
        return Response::generate(0, 'ok', [
            'redirect' => $ret['data']['redirect'],
        ]);
    }

    public function ssoClientLogoutPrepare()
    {
        if (!modstart_config('ssoClientEnable', false)) {
            return Response::generate(-1, '请开启 同步登录客户端');
        }
        $input = InputPackage::buildFromInput();
        $domainUrl = $input->getTrimString('domainUrl');

        $ssoClientServer = modstart_config('ssoClientServer', '');
        if (empty($ssoClientServer)) {
            return Response::generate(-1, '请配置 同步登录服务端地址');
        }

        $redirect = $ssoClientServer . '_logout' . '?' . http_build_query(['redirect' => $domainUrl . '/sso/client_logout',]);
        return Response::generate(0, 'ok', [
            'redirect' => $redirect,
        ]);
    }

    public function ssoClientLogout()
    {
        if (!modstart_config('ssoClientEnable', false)) {
            return Response::generate(-1, '请开启 同步登录客户端');
        }
        Session::forget('memberUserId');
        return Response::generate(0, 'ok');
    }

    public function ssoServerLogout()
    {
        if (!modstart_config('ssoServerEnable', false)) {
            return Response::generate(-1, '请开启 同步登录服务端');
        }
        Session::forget('memberUserId');
        return Response::generate(0, 'ok');
    }

    public function ssoServerSuccess()
    {
        if (!modstart_config('ssoServerEnable', false)) {
            return Response::generate(-1, '请开启 同步登录服务端');
        }

        $memberUserId = Session::get('memberUserId', 0);
        if (!$memberUserId) {
            return Response::generate(-1, '未登录');
        }
        $memberUser = MemberUtil::get($memberUserId);
        $ssoServerSecret = modstart_config('ssoServerSecret');
        if (empty($ssoServerSecret)) {
            return Response::generate(-1, '请设置 同步登录服务端通讯秘钥');
        }

        $input = InputPackage::buildFromInput();
        $client = $input->getTrimString('client');
        $domainUrl = $input->getTrimString('domainUrl');
        if (empty($domainUrl) || empty($client)) {
            return Response::generate(-1, '数据错误');
        }
        $ssoClientList = explode("\n", modstart_config('ssoServerClientList', ''));
        $valid = false;
        foreach ($ssoClientList as $item) {
            if (trim($item) == $client) {
                $valid = true;
            }
        }
        if (!$valid) {
            return Response::generate(-1, '数据错误(2)');
        }
        $server = $domainUrl . '/sso/server';
        $timestamp = time();
        $username = $memberUser['username'];
        $sign = md5(md5($ssoServerSecret) . md5($timestamp . '') . md5($server) . md5($username));

        $redirect = $client
            . '?server=' . urlencode($server)
            . '&timestamp=' . $timestamp
            . '&username=' . urlencode(base64_encode($username))
            . '&sign=' . $sign;

        return Response::generate(0, null, [
            'redirect' => $redirect
        ]);
    }

    public function ssoServer()
    {
        if (!modstart_config('ssoServerEnable', false)) {
            return Response::generate(-1, '请开启 同步登录服务端');
        }
        $input = InputPackage::buildFromInput();
        $client = $input->getTrimString('client');
        $timestamp = $input->getInteger('timestamp');
        $sign = $input->getTrimString('sign');
        if (empty($client)) {
            return Response::generate(-1, 'client 为空');
        }
        if (empty($timestamp)) {
            return Response::generate(-1, 'timestamp 为空');
        }
        if (empty($sign)) {
            return Response::generate(-1, 'sign 为空');
        }
        $ssoSecret = modstart_config('ssoServerSecret');
        if (empty($ssoSecret)) {
            return Response::generate(-1, '请设置 同步登录服务端通讯秘钥');
        }
        $signCalc = md5(md5($ssoSecret) . md5($timestamp . '') . md5($client));
        if ($sign != $signCalc) {
            return Response::generate(-1, 'sign 错误');
        }
        if (abs(time() - $timestamp) > 3600) {
            return Response::generate(-1, 'timestamp 错误');
        }
        $ssoClientList = explode("\n", modstart_config('ssoServerClientList', ''));
        $valid = false;
        foreach ($ssoClientList as $item) {
            if (trim($item) == $client) {
                $valid = true;
            }
        }
        if (!$valid) {
            return Response::generate(-1, '请在 同步登陆服务端增加客户端地址 ' . $client);
        }
        $isLogin = false;
        if (intval(Session::get('memberUserId', 0)) > 0) {
            $isLogin = true;
        }
        return Response::generate(0, 'ok', [
            'isLogin' => $isLogin,
        ]);
    }

    public function ssoClient()
    {
        if (!modstart_config('ssoClientEnable', false)) {
            return Response::generate(-1, '请开启 同步登录客户端');
        }
        $ssoClientServer = modstart_config('ssoClientServer', '');
        if (empty($ssoClientServer)) {
            return Response::generate(-1, '请配置 同步登录服务端地址');
        }

        $ssoClientSecret = modstart_config('ssoClientSecret');
        if (empty($ssoClientSecret)) {
            return Response::generate(-1, '请设置 同步登录客户端通讯秘钥');
        }

        $input = InputPackage::buildFromInput();
        $server = $input->getTrimString('server');
        $timestamp = $input->getInteger('timestamp');
        $sign = $input->getTrimString('sign');
        $username = @base64_decode($input->getTrimString('username'));

        if (empty($username)) {
            return Response::generate(-1, '同步登录返回的用户名为空');
        }
        if (empty($timestamp)) {
            return Response::generate(-1, 'timestamp为空');
        }
        if (empty($sign)) {
            return Response::generate(-1, 'sign为空');
        }
        $signCalc = md5(md5($ssoClientSecret) . md5($timestamp . '') . md5($server) . md5($username));
        if ($sign != $signCalc) {
            return Response::generate(-1, 'sign错误');
        }
        if (abs(time() - $timestamp) > 3600) {
            return Response::generate(-1, 'timestamp错误');
        }
        if ($server != $ssoClientServer) {
            return Response::generate(-1, '同步登录 服务端地址不是配置的' . $ssoClientServer);
        }
        $memberUser = MemberUtil::getByUsername($username);
        if (empty($memberUser)) {
            $ret = MemberUtil::register($username, null, null, null, true);
            if ($ret['code']) {
                return Response::generate(-1, $ret['msg']);
            }
            $memberUser = MemberUtil::get($ret['data']['id']);
        }
        Session::put('memberUserId', $memberUser['id']);
        MemberUtil::fireLogin($memberUser['id']);
        return Response::generate(0, 'ok');
    }

    public function ssoClientPrepare()
    {
        if (!modstart_config('ssoClientEnable', false)) {
            return Response::generate(-1, 'SSO未开启');
        }
        $ssoClientServer = modstart_config('ssoClientServer');
        $ssoClientSecret = modstart_config('ssoClientSecret');
        $input = InputPackage::buildFromInput();
        $client = $input->getTrimString('client', '/');
        if (!Str::endsWith($client, '/sso/client')) {
            return Response::generate(-1, 'client参数错误');
        }
        $timestamp = time();
        $sign = md5(md5($ssoClientSecret) . md5($timestamp . '') . md5($client));
        $redirect = $ssoClientServer . '?client=' . urlencode($client) . '&timestamp=' . $timestamp . '&sign=' . $sign;
        return Response::generate(0, 'ok', [
            'redirect' => $redirect,
        ]);
    }


    public function logout()
    {
        $memberUserId = MemberUser::id();
        Session::forget('memberUserId');
        if ($memberUserId > 0) {
            EventUtil::fire(new MemberUserLogoutEvent($memberUserId));
        }
        return Response::generateSuccess();
    }


    public function login()
    {
        $input = InputPackage::buildFromInput();

        $username = $input->getTrimString('username');
        $password = $input->getTrimString('password');
        if (empty($username)) {
            return Response::generate(-1, '请输入用户');
        }
        if (empty($password)) {
            return Response::generate(-1, '请输入密码');
        }

        if (modstart_config('loginCaptchaEnable', false)) {
            $captchaProvider = SecurityUtil::loginCaptchaProvider();
            if ($captchaProvider) {
                $ret = $captchaProvider->validate();
                if (Response::isError($ret)) {
                    return Response::generate(-1, $ret['msg']);
                }
            } else {
                if (!CaptchaFacade::check($input->getTrimString('captcha'))) {
                    return Response::generate(ResponseCodes::CAPTCHA_ERROR, '登录失败:图片验证码错误', null, '[js]$(\'[data-captcha]\').click();');
                }
            }
        }
        $memberUser = null;
        $loginMsg = null;
        if (!$memberUser) {
            $ret = MemberUtil::login($username, null, null, $password);
            if (0 == $ret['code']) {
                $memberUser = $ret['data'];
            }
        }
        if (!$memberUser) {
            $ret = MemberUtil::login(null, $username, null, $password);
            if (0 == $ret['code']) {
                $memberUser = $ret['data'];
            }
        }
        if (!$memberUser) {
            $ret = MemberUtil::login(null, null, $username, $password);
            if (0 == $ret['code']) {
                $memberUser = $ret['data'];
            }
        }
        if (!$memberUser) {
            $failedTip = Session::pull('memberUserLoginFailedTip', null);
            return Response::generate(ResponseCodes::CAPTCHA_ERROR, '登录失败:用户或密码错误' . ($failedTip ? '，' . $failedTip : ''));
        }
        Session::put('memberUserId', $memberUser['id']);
        MemberUtil::fireLogin($memberUser['id']);
        EventUtil::fire(new MemberUserLoginedEvent($memberUser['id']));
        return Response::generateSuccess();
    }

    public function loginCaptchaRaw()
    {
        return CaptchaFacade::create('default');
    }

    public function loginPhoneCaptchaRaw()
    {
        return CaptchaFacade::create('default');
    }


    public function loginPhone()
    {
        if (!modstart_config('Member_LoginPhoneEnable', false)) {
            return Response::generate(-1, '手机快捷登录未开启');
        }
        $input = InputPackage::buildFromInput();
        $phone = $input->getPhone('phone');
        $verify = $input->getTrimString('verify');
        if (empty($phone)) {
            return Response::generate(-1, '手机为空或不正确');
        }
        if (empty($verify)) {
            return Response::generate(-1, '验证码不能为空');
        }
        if ($verify != Session::get('loginPhoneVerify')) {
            return Response::generate(-1, '手机验证码不正确');
        }
        if (Session::get('loginPhoneVerifyTime') + 60 * 60 < time()) {
            return Response::generate(0, '手机验证码已过期');
        }
        if ($phone != Session::get('loginPhone')) {
            return Response::generate(-1, '两次手机不一致');
        }
        $memberUser = MemberUtil::getByPhone($phone);
        if (empty($memberUser) && modstart_config('Member_LoginPhoneAutoRegister', false)) {
            foreach (MemberRegisterProcessorProvider::listAll() as $provider) {

                $ret = $provider->preCheck();
                if (Response::isError($ret)) {
                    return $ret;
                }
            }
            $ret = MemberUtil::register(null, $phone, null, null, true);
            if ($ret['code']) {
                return Response::generate(-1, $ret['msg']);
            }
            $memberUserId = $ret['data']['id'];
            MemberUtil::autoSetUsernameNickname($memberUserId, modstart_config('Member_LoginPhoneNameSuggest', '用户'));
            $update = [];
            $update['phoneVerified'] = true;
            $update['registerIp'] = StrUtil::mbLimit(Request::ip(), 20);
            if (!empty($update)) {
                MemberUtil::update($memberUserId, $update);
            }
            EventUtil::fire(new MemberUserRegisteredEvent($memberUserId));
            Session::forget('registerCaptchaPass');
            foreach (MemberRegisterProcessorProvider::listAll() as $provider) {

                $provider->postProcess($memberUserId);
            }
            $memberUser = MemberUtil::get($memberUserId);
        }
        if (empty($memberUser)) {
            return Response::generate(-1, '手机没有绑定任何账号');
        }
        Session::forget('loginPhoneVerify');
        Session::forget('loginPhoneVerifyTime');
        Session::forget('loginPhone');
        Session::put('memberUserId', $memberUser['id']);
        MemberUtil::fireLogin($memberUser['id']);
        EventUtil::fire(new MemberUserLoginedEvent($memberUser));
        return Response::generate(0, null);
    }


    public function loginPhoneVerify()
    {
        if (!modstart_config('Member_LoginPhoneEnable', false)) {
            return Response::generate(-1, '手机快捷登录未开启');
        }

        $input = InputPackage::buildFromInput();
        $phone = $input->getPhone('target');
        if (empty($phone)) {
            return Response::generate(-1, '手机为空或格式不正确');
        }

        $provider = SecurityUtil::loginCaptchaProvider();
        if ($provider) {
            $ret = $provider->validate();
            if (Response::isError($ret)) {
                return $ret;
            }
        } else {
            $captcha = $input->getTrimString('captcha');
            if (!CaptchaFacade::check($captcha)) {
                return Response::generate(-1, '图片验证码错误');
            }
        }

        $memberUser = MemberUtil::getByPhone($phone);
        if (empty($memberUser) && !modstart_config('Member_LoginPhoneAutoRegister', false)) {
            return Response::generate(-1, '手机没有绑定任何账号');
        }

        if (Session::get('loginPhoneVerifyTime') && $phone == Session::get('loginPhone')) {
            if (Session::get('loginPhoneVerifyTime') + 60 > time()) {
                return Response::generate(-1, '验证码发送频繁，请稍后再试!');
            }
        }

        $verify = rand(100000, 999999);
        Session::put('loginPhoneVerify', $verify);
        Session::put('loginPhoneVerifyTime', time());
        Session::put('loginPhone', $phone);

        SmsSendJob::create($phone, 'verify', ['code' => $verify]);

        return Response::generate(0, '验证码发送成功');
    }


    public function loginPhoneCaptcha()
    {
        $captcha = $this->loginCaptchaRaw();
        return Response::generate(0, 'ok', [
            'image' => 'data:image/png;base64,' . base64_encode($captcha->getOriginalContent()),
        ]);
    }


    public function loginCaptcha()
    {
        $captcha = $this->loginCaptchaRaw();
        return Response::generate(0, 'ok', [
            'image' => 'data:image/png;base64,' . base64_encode($captcha->getOriginalContent()),
        ]);
    }


    public function registerPhone()
    {
        if (modstart_config('registerDisable', false)) {
            return Response::generate(-1, '禁止注册');
        }
        if (!modstart_config('Member_RegisterPhoneEnable', false)) {
            return Response::generate(-1, '手机快速注册未开启');
        }
        $input = InputPackage::buildFromInput();
        if (modstart_config('Member_AgreementEnable', false)) {
            if (!$input->getBoolean('agreement')) {
                return Response::generateError('请先同意 ' . modstart_config('Member_AgreementTitle', '用户使用协议'));
            }
        }
        $phone = $input->getPhone('phone');
        $phoneVerify = $input->getTrimString('phoneVerify');
        $zw = $input->getTrimString('zw');
        $gs = $input->getTrimString('gs');
        if (empty($phone)) {
            return Response::generate(-1, '请输入手机');
        }
        if ($phoneVerify != Session::get('registerPhoneVerify')) {
            return Response::generate(-1, '手机验证码不正确.');
        }
        if (Session::get('registerPhoneVerifyTime') + 60 * 60 < time()) {
            return Response::generate(-1, '手机验证码已过期');
        }
        if ($phone != Session::get('registerPhone')) {
            return Response::generate(-1, '两次手机不一致');
        }

        foreach (MemberRegisterProcessorProvider::listAll() as $provider) {

            $ret = $provider->preCheck();
            if (Response::isError($ret)) {
                return $ret;
            }
        }

        $ret = MemberUtil::register(null, $phone, null, null, true);
        if ($ret['code']) {
            return Response::generate(-1, $ret['msg']);
        }
        $memberUserId = $ret['data']['id'];
        MemberUtil::autoSetUsernameNickname($memberUserId, modstart_config('Member_LoginPhoneNameSuggest', '用户'));
        $update = [];
        $update['phoneVerified'] = true;
        $update['registerIp'] = StrUtil::mbLimit(Request::ip(), 20);
        if (!empty($update)) {
            MemberUtil::update($memberUserId, $update);
        }
        EventUtil::fire(new MemberUserRegisteredEvent($memberUserId));
        Session::forget('registerCaptchaPass');
        foreach (MemberRegisterProcessorProvider::listAll() as $provider) {

            $provider->postProcess($memberUserId);
        }
        Session::put('memberUserId', $memberUserId);
        MemberUtil::fireLogin($memberUserId);
        EventUtil::fire(new MemberUserLoginedEvent($memberUserId));
        return Response::generate(0, '注册成功', [
            'id' => $memberUserId,
        ]);
    }


    public function register()
    {
        if (modstart_config('registerDisable', false)) {
            return Response::generate(-1, '禁止注册');
        }

        $input = InputPackage::buildFromInput();

        if (modstart_config('Member_AgreementEnable', false)) {
            if (!$input->getBoolean('agreement')) {
                return Response::generateError('请先同意 ' . modstart_config('Member_AgreementTitle', '用户使用协议'));
            }
        }

        $username = $input->getTrimString('username');
        $phone = $input->getPhone('phone');
        $phoneVerify = $input->getTrimString('phoneVerify');
        $email = $input->getEmail('email');
        $emailVerify = $input->getTrimString('emailVerify');
        $password = $input->getTrimString('password');
        $passwordRepeat = $input->getTrimString('passwordRepeat');
        $captcha = $input->getTrimString('captcha');
        $zw = $input->getTrimString('zw');
        $gs = $input->getTrimString('gs');
        $ly = $input->getTrimString('ly');
        $tynumber = $input->getTrimString('tynumber');
        if (empty($zw)) {
            return Response::generate(-1, '职位不能为空');
        }
        if (empty($gs)) {
            return Response::generate(-1, '公司不能为空');
        }
        if (empty($tynumber)) {
            return Response::generate(-1, '统一社会信用号不能为空');
        }
        if (empty($username)) {
            return Response::generate(-1, '用户名不能为空');
        }

        if (Str::contains($username, '@')) {
            return Response::generate(-1, '用户名不能包含特殊字符');
        }
        if (preg_match('/^\\d{11}$/', $username)) {
            return Response::generate(-1, '用户名不能为纯数字');
        }

        if (!Session::get('registerCaptchaPass', false)) {
            if (!CaptchaFacade::check($captcha)) {
                SessionUtil::atomicProduce('registerCaptchaPassCount', 1);
                return Response::generate(-1, '请重新进行安全验证');
            }
        }
        if (!SessionUtil::atomicConsume('registerCaptchaPassCount')) {
            return Response::generate(-1, '请进行安全验证');
        }

        if (modstart_config('registerPhoneEnable')) {
            if (empty($phone)) {
                return Response::generate(-1, '请输入手机');
            }
            if ($phoneVerify != Session::get('registerPhoneVerify')) {
                return Response::generate(-1, '手机验证码不正确.');
            }
            if (Session::get('registerPhoneVerifyTime') + 60 * 60 < time()) {
                return Response::generate(-1, '手机验证码已过期');
            }
            if ($phone != Session::get('registerPhone')) {
                return Response::generate(-1, '两次手机不一致');
            }
        }
        if (modstart_config('registerEmailEnable')) {
            if (empty($email)) {
                return Response::generate(-1, '请输入邮箱');
            }
            if ($emailVerify != Session::get('registerEmailVerify')) {
                return Response::generate(-1, '邮箱验证码不正确.');
            }
            if (Session::get('registerEmailVerifyTime') + 60 * 60 < time()) {
                return Response::generate(-1, '邮箱验证码已过期');
            }
            if ($email != Session::get('registerEmail')) {
                return Response::generate(-1, '两次邮箱不一致');
            }
        }
        if (empty($password)) {
            return Response::generate(-1, '请输入密码');
        }
        if ($password != $passwordRepeat) {
            return Response::generate(-1, '两次输入密码不一致');
        }

        foreach (MemberRegisterProcessorProvider::listAll() as $provider) {

            $ret = $provider->preCheck();
            if (Response::isError($ret)) {
                return $ret;
            }
        }

        $ret = MemberUtil::register($username, $phone, $email, $password,$zw,$gs,$ly,$tynumber);
        if ($ret['code']) {
            return Response::generate(-1, $ret['msg']);
        }
        $memberUserId = $ret['data']['id'];
        $update = [];
        if (modstart_config('registerPhoneEnable')) {
            $update['phoneVerified'] = true;
        }
        if (modstart_config('registerEmailEnable')) {
            $update['emailVerified'] = true;
        }
        $update['registerIp'] = StrUtil::mbLimit(Request::ip(), 20);
        if (!empty($update)) {
            MemberUtil::update($memberUserId, $update);
        }
        EventUtil::fire(new MemberUserRegisteredEvent($memberUserId));
        Session::forget('registerCaptchaPass');
        foreach (MemberRegisterProcessorProvider::listAll() as $provider) {

            $provider->postProcess($memberUserId);
        }
        return Response::generate(0, '注册成功', [
            'id' => $memberUserId,
        ]);
    }


    public function registerEmailVerify()
    {
        if (modstart_config('registerDisable', false)) {
            return Response::generate(-1, '禁止注册');
        }
        if (!modstart_config('registerEmailEnable')) {
            return Response::generate(-1, '注册未开启邮箱');
        }
        $input = InputPackage::buildFromInput();

        $email = $input->getEmail('target');
        if (empty($email)) {
            return Response::generate(-1, '邮箱不能为空');
        }

        if (!Session::get('registerCaptchaPass', false)) {
            return Response::generate(-1, '请先进行安全验证');
        }
        if (!SessionUtil::atomicConsume('registerCaptchaPassCount')) {
            return Response::generate(-1, '请进行安全验证');
        }

        $memberUser = MemberUtil::getByEmail($email);
        if (!empty($memberUser)) {
            return Response::generate(-1, '邮箱已经被占用');
        }

        if (Session::get('registerEmailVerifyTime') && $email == Session::get('registerEmail')) {
            if (Session::get('registerEmailVerifyTime') + 60 > time()) {
                return Response::generate(-1, '验证码发送频繁，请稍后再试!');
            }
        }

        $verify = rand(100000, 999999);

        MailSendJob::create($email, '注册账户验证码', 'verify', ['code' => $verify]);

        Session::put('registerEmailVerify', $verify);
        Session::put('registerEmailVerifyTime', time());
        Session::put('registerEmail', $email);

        return Response::generate(0, '验证码发送成功');
    }


    public function registerPhoneVerify()
    {
        if (modstart_config('registerDisable', false)) {
            return Response::generate(-1, '禁止注册');
        }

        if (!modstart_config('registerPhoneEnable')) {
            return Response::generate(-1, '注册未开启手机');
        }
        $input = InputPackage::buildFromInput();

        $phone = $input->getPhone('target');
        if (empty($phone)) {
            return Response::generate(-1, '手机不能为空');
        }

        if (!Session::get('registerCaptchaPass', false)) {
            return Response::generate(-1, '请先进行安全验证');
        }
        if (!SessionUtil::atomicConsume('registerCaptchaPassCount')) {
            return Response::generate(-1, '请进行安全验证');
        }

        $memberUser = MemberUtil::getByPhone($phone);
        if (!empty($memberUser)) {
            return Response::generate(-1, '手机已经被占用');
        }

        if (Session::get('registerPhoneVerifyTime') && $phone == Session::get('registerPhone')) {
            if (Session::get('registerPhoneVerifyTime') + 60 > time()) {
                return Response::generate(0, '验证码发送成功!');
            }
        }

        $verify = rand(1000, 9999);
        $text = '【正博】您的验证码是' . $verify . '。如非本人操作，请忽略本短信';

        // 初始化云片客户端封装器
        $params = [
            'apikey' => 'b483a4035fe49194403e5e1b3527b710',
            'mobile' => $phone,
            'text' => $text,
        ];
        $yun=new YunpianSmsService();
        $res = $yun->post("https://sms.yunpian.com/v2/sms/single_send.json", $params);
        Log::info($res);
        if ($res['code']!=0){
            return Response::generate(-1, $res['msg'].$res['detail']);
        }
        // 要发送的手机号码和短信内容
        // 发送短信


        Session::put('registerPhoneVerify', $verify);
        Session::put('registerPhoneVerifyTime', time());
        Session::put('registerPhone', $phone);

        return Response::generate(0, '验证码发送成功');
    }


    public function registerCaptchaVerify()
    {
        $provider = SecurityUtil::registerCaptchaProvider();
        if ($provider) {
            $ret = $provider->validate();
            if (Response::isError($ret)) {
                return $ret;
            }
        } else {
            $input = InputPackage::buildFromInput();
            $captcha = $input->getTrimString('captcha');
            if (!CaptchaFacade::check($captcha)) {
                SessionUtil::atomicRemove('registerCaptchaPassCount');
                return Response::generate(ResponseCodes::CAPTCHA_ERROR, '验证码错误');
            }
        }
        Session::put('registerCaptchaPass', true);
        $registerCaptchaPassCount = 1;
        if (modstart_config('registerEmailEnable')) {
            $registerCaptchaPassCount++;
        }
        if (modstart_config('registerPhoneEnable')) {
            $registerCaptchaPassCount++;
        }
        SessionUtil::atomicProduce('registerCaptchaPassCount', $registerCaptchaPassCount);
        return Response::generateSuccess();
    }

    public function oauthBindCaptchaVerify()
    {
        $input = InputPackage::buildFromInput();
        $captcha = $input->getTrimString('captcha');
        if (!CaptchaFacade::check($captcha)) {
            SessionUtil::atomicRemove('oauthBindCaptchaPassCount');
            return Response::generate(ResponseCodes::CAPTCHA_ERROR, '验证码错误');
        }
        Session::put('oauthBindCaptchaPass', true);
        $passCount = 1;
        if (modstart_config('Member_OauthBindPhoneEnable')) {
            $passCount++;
        }
        if (modstart_config('Member_OauthBindEmailEnable')) {
            $passCount++;
        }
        SessionUtil::atomicProduce('oauthBindCaptchaPassCount', $passCount);
        return Response::generateSuccess();
    }

    public function oauthBindCaptchaRaw()
    {
        return CaptchaFacade::create('default');
    }

    public function oauthBindCaptcha()
    {
        Session::forget('oauthBindCaptchaPass');
        $captcha = $this->oauthBindCaptchaRaw();
        return Response::generate(0, 'ok', [
            'image' => 'data:image/png;base64,' . base64_encode($captcha->getOriginalContent()),
        ]);
    }


    public function oauthBindEmailVerify()
    {
        if (!modstart_config('Member_OauthBindEmailEnable')) {
            return Response::generate(-1, '授权登录未开启邮箱');
        }
        $input = InputPackage::buildFromInput();

        $email = $input->getEmail('target');
        if (empty($email)) {
            return Response::generate(-1, '邮箱不能为空');
        }

        if (!Session::get('oauthBindCaptchaPass', false)) {
            return Response::generate(-1, '请先进行安全验证');
        }
        if (!SessionUtil::atomicConsume('oauthBindCaptchaPassCount')) {
            return Response::generate(-1, '请进行安全验证');
        }

        $memberUser = MemberUtil::getByEmail($email);
        if (!empty($memberUser)) {
            return Response::generate(-1, '邮箱已经被占用');
        }

        if (Session::get('oauthBindEmailVerifyTime') && $email == Session::get('oauthBindEmail')) {
            if (Session::get('oauthBindEmailVerifyTime') + 60 > time()) {
                return Response::generate(-1, '验证码发送频繁，请稍后再试!');
            }
        }

        $verify = rand(100000, 999999);

        MailSendJob::create($email, '注册账户验证码', 'verify', ['code' => $verify]);

        Session::put('oauthBindEmailVerify', $verify);
        Session::put('oauthBindEmailVerifyTime', time());
        Session::put('oauthBindEmail', $email);

        return Response::generate(0, '验证码发送成功');
    }


    public function oauthBindPhoneVerify()
    {
        if (!modstart_config('Member_OauthBindPhoneEnable')) {
            return Response::generate(-1, '注册未开启手机');
        }
        $input = InputPackage::buildFromInput();

        $phone = $input->getPhone('target');
        if (empty($phone)) {
            return Response::generate(-1, '手机不能为空');
        }

        if (!Session::get('oauthBindCaptchaPass', false)) {
            return Response::generate(-1, '请先进行安全验证');
        }
        if (!SessionUtil::atomicConsume('oauthBindCaptchaPassCount')) {
            return Response::generate(-1, '请进行安全验证');
        }

        $memberUser = MemberUtil::getByPhone($phone);
        if (!empty($memberUser)) {
            return Response::generate(-1, '手机已经被占用');
        }

        if (Session::get('oauthBindPhoneVerifyTime') && $phone == Session::get('oauthBindPhone')) {
            if (Session::get('oauthBindPhoneVerifyTime') + 60 > time()) {
                return Response::generate(0, '验证码发送成功!');
            }
        }

        $verify = rand(100000, 999999);

        SmsSendJob::create($phone, 'verify', ['code' => $verify]);

        Session::put('oauthBindPhoneVerify', $verify);
        Session::put('oauthBindPhoneVerifyTime', time());
        Session::put('oauthBindPhone', $phone);

        return Response::generate(0, '验证码发送成功');
    }

    public function registerCaptchaRaw()
    {
        return CaptchaFacade::create('default');
    }


    public function registerCaptcha()
    {
        Session::forget('registerCaptchaPass');
        $captcha = $this->registerCaptchaRaw();
        return Response::generate(0, 'ok', [
            'image' => 'data:image/png;base64,' . base64_encode($captcha->getOriginalContent()),
        ]);
    }


    public function retrievePhone()
    {
        if (modstart_config('retrieveDisable', false)) {
            return Response::generate(-1, '找回密码已禁用');
        }
        $input = InputPackage::buildFromInput();
        if (!modstart_config('retrievePhoneEnable', false)) {
            return Response::generate(-1, '找回密码没有开启');
        }
        $phone = $input->getPhone('phone');
        $verify = $input->getTrimString('verify');
        if (empty($phone)) {
            return Response::generate(-1, '手机为空或不正确');
        }
        if (empty($verify)) {
            return Response::generate(-1, '验证码不能为空');
        }
        if ($verify != Session::get('retrievePhoneVerify')) {
            return Response::generate(-1, '手机验证码不正确');
        }
        if (Session::get('retrievePhoneVerifyTime') + 60 * 60 < time()) {
            return Response::generate(0, '手机验证码已过期');
        }
        if ($phone != Session::get('retrievePhone')) {
            return Response::generate(-1, '两次手机不一致');
        }
        $memberUser = MemberUtil::getByPhone($phone);
        if (empty($memberUser)) {
            return Response::generate(-1, '手机没有绑定任何账号');
        }
        Session::forget('retrievePhoneVerify');
        Session::forget('retrievePhoneVerifyTime');
        Session::forget('retrievePhone');
        Session::put('retrieveMemberUserId', $memberUser['id']);
        return Response::generate(0, null);
    }


    public function retrievePhoneVerify()
    {
        if (modstart_config('retrieveDisable', false)) {
            return Response::generate(-1, '找回密码已禁用');
        }

        $input = InputPackage::buildFromInput();
        $phone = $input->getPhone('target');
        if (empty($phone)) {
            return Response::generate(-1, '手机为空或格式不正确');
        }

        $captcha = $input->getTrimString('captcha');
        if (!CaptchaFacade::check($captcha)) {
            return Response::generate(-1, '图片验证码错误');
        }

        $memberUser = MemberUtil::getByPhone($phone);
        if (empty($memberUser)) {
            return Response::generate(-1, '手机没有绑定任何账号');
        }

        if (Session::get('retrievePhoneVerifyTime') && $phone == Session::get('retrievePhone')) {
            if (Session::get('retrievePhoneVerifyTime') + 60 * 2 > time()) {
                return Response::generate(0, '验证码发送成功!');
            }
        }

        $verify = rand(100000, 999999);
        Session::put('retrievePhoneVerify', $verify);
        Session::put('retrievePhoneVerifyTime', time());
        Session::put('retrievePhone', $phone);

        SmsSendJob::create($phone, 'verify', ['code' => $verify]);

        return Response::generate(0, '验证码发送成功');
    }


    public function retrieveEmail()
    {
        if (modstart_config('retrieveDisable', false)) {
            return Response::generate(-1, '找回密码已禁用');
        }

        if (!modstart_config('retrieveEmailEnable', false)) {
            return Response::generate(-1, '找回密码没有开启');
        }

        $input = InputPackage::buildFromInput();

        $email = $input->getEmail('email');
        $verify = $input->getTrimString('verify');

        if (empty($email)) {
            return Response::generate(-1, '邮箱为空或格式不正确');
        }
        if (empty($verify)) {
            return Response::generate(-1, '验证码不能为空');
        }
        if ($verify != Session::get('retrieveEmailVerify')) {
            return Response::generate(-1, '邮箱验证码不正确');
        }
        if (Session::get('retrieveEmailVerifyTime') + 60 * 60 < time()) {
            return Response::generate(0, '邮箱验证码已过期');
        }
        if ($email != Session::get('retrieveEmail')) {
            return Response::generate(-1, '两次邮箱不一致');
        }

        $memberUser = MemberUtil::getByEmail($email);
        if (empty($memberUser)) {
            return Response::generate(-1, '邮箱没有绑定任何账号');
        }

        Session::forget('retrieveEmailVerify');
        Session::forget('retrieveEmailVerifyTime');
        Session::forget('retrieveEmail');

        Session::put('retrieveMemberUserId', $memberUser['id']);

        return Response::generate(0, null);
    }


    public function retrieveEmailVerify()
    {
        if (modstart_config('retrieveDisable', false)) {
            return Response::generate(-1, '找回密码已禁用');
        }

        $input = InputPackage::buildFromInput();

        $email = $input->getEmail('target');
        if (empty($email)) {
            return Response::generate(-1, '邮箱格式不正确或为空');
        }

        $captcha = $input->getTrimString('captcha');
        if (!CaptchaFacade::check($captcha)) {
            return Response::generate(-1, '图片验证码错误');
        }

        $memberUser = MemberUtil::getByEmail($email);
        if (empty($memberUser)) {
            return Response::generate(-1, '邮箱没有绑定任何账号');
        }

        if (Session::get('retrieveEmailVerifyTime') && $email == Session::get('retrieveEmail')) {
            if (Session::get('retrieveEmailVerifyTime') + 60 > time()) {
                return Response::generate(0, '验证码发送成功!');
            }
        }

        $verify = rand(100000, 999999);

        MailSendJob::create($email, '找回密码验证码', 'verify', ['code' => $verify]);

        Session::put('retrieveEmailVerify', $verify);
        Session::put('retrieveEmailVerifyTime', time());
        Session::put('retrieveEmail', $email);

        return Response::generate(0, '验证码发送成功');
    }


    public function retrieveResetInfo()
    {
        $retrieveMemberUserId = Session::get('retrieveMemberUserId');
        if (empty($retrieveMemberUserId)) {
            return Response::generate(-1, '请求错误');
        }
        $memberUser = MemberUtil::get($retrieveMemberUserId);
        $username = $memberUser['username'];
        if (empty($username)) {
            $username = $memberUser['phone'];
        }
        if (empty($username)) {
            $username = $memberUser['email'];
        }
        return Response::generate(0, null, [
            'memberUser' => [
                'username' => $username,
            ]
        ]);
    }


    public function retrieveReset()
    {
        if (modstart_config('retrieveDisable', false)) {
            return Response::generate(-1, '找回密码已禁用');
        }

        $input = InputPackage::buildFromInput();
        $retrieveMemberUserId = Session::get('retrieveMemberUserId');
        if (empty($retrieveMemberUserId)) {
            return Response::generate(-1, '请求错误');
        }
        $password = $input->getTrimString('password');
        $passwordRepeat = $input->getTrimString('passwordRepeat');
        if (empty($password)) {
            return Response::generate(-1, '请输入密码');
        }
        if ($password != $passwordRepeat) {
            return Response::generate(-1, '两次输入密码不一致');
        }
        $memberUser = MemberUtil::get($retrieveMemberUserId);
        if (empty($memberUser)) {
            return Response::generate(-1, '用户不存在');
        }
        $ret = MemberUtil::changePassword($memberUser['id'], $password, null, true);
        if ($ret['code']) {
            return Response::generate(-1, $ret['msg']);
        }
        EventUtil::fire(new MemberUserPasswordResetedEvent($memberUser['id'], $password));
        Session::forget('retrieveMemberUserId');
        return Response::generate(0, '成功设置新密码,请您登录');
    }

    public function retrieveCaptchaRaw()
    {
        return CaptchaFacade::create('default');
    }


    public function retrieveCaptcha()
    {
        $captcha = $this->retrieveCaptchaRaw();
        return Response::generate(0, 'ok', [
            'image' => 'data:image/png;base64,' . base64_encode($captcha->getOriginalContent()),
        ]);
    }
}
