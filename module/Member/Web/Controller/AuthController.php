<?php


namespace Module\Member\Web\Controller;

use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Session;
use ModStart\Core\Input\InputPackage;
use ModStart\Core\Input\Request;
use ModStart\Core\Input\Response;
use ModStart\Module\ModuleBaseController;
use Module\Member\Auth\MemberUser;



class AuthController extends ModuleBaseController
{
    
    private $api;

    public function __construct()
    {
        $this->api = app(\Module\Member\Api\Controller\AuthController::class);
    }

    public function login()
    {
        $input = InputPackage::buildFromInput();
        $dialog = $input->getInteger('dialog');
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $redirectData = [
            'redirect' => $redirect,
        ];
        if ($dialog) {
            $redirectData['dialog'] = $dialog;
        }
        $this->api->checkRedirectSafety($redirect);
        if (Request::isPost()) {
            $ret = $this->api->login();
            if (Response::isError($ret)) {
                return Response::sendFromGenerate($ret);
            }
            if ($dialog) {
                return Response::send(0, '', '', '[js]parent.location.href=' . json_encode($redirect) . ';');
            }
            return Response::send(0, '', '', $redirect);
        }
        $loginDefault = modstart_config('Member_LoginDefault', 'default');
        if ('sso' == $loginDefault && modstart_config('ssoClientEnable', false)) {
            $force = $input->getBoolean('force', false);
            if (!$force) {
                return Response::redirect(modstart_web_url('login/sso', $redirectData));
            }
        } else if ('phone' == $loginDefault && modstart_config('Member_LoginPhoneEnable', false)) {
            $force = $input->getBoolean('force', false);
            if (!$force) {
                return Response::redirect(modstart_web_url('login/phone', $redirectData));
            }
        }
        $view = 'login';
        if ($dialog) {
            $view = 'loginDialog';
        }
        return $this->view($view, $redirectData);
    }

    public function loginSso()
    {
        if (!modstart_config('ssoClientEnable', false)) {
            return Response::generateError('SSO登录未开启');
        }
        $input = InputPackage::buildFromInput();
        $dialog = $input->getInteger('dialog');
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $redirectData = [
            'redirect' => $redirect,
        ];
        if ($dialog) {
            $redirectData['dialog'] = $dialog;
        }
        $this->api->checkRedirectSafety($redirect);
        Input::merge(['client' => Request::domainUrl() . '/sso/client']);
        $ret = $this->api->ssoClientPrepare();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        Session::put('ssoClientRedirect', $redirect);
        if ($dialog) {
            return Response::send(0, '', '', '[js]parent.location.href=' . json_encode($ret['data']['redirect']) . ';');
        }
        return Response::send(0, null, null, $ret['data']['redirect']);
    }

    public function loginOther()
    {
        $input = InputPackage::buildFromInput();
        $dialog = $input->getInteger('dialog');
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $redirectData = [
            'redirect' => $redirect,
        ];
        if ($dialog) {
            $redirectData['dialog'] = $dialog;
        }
        $this->api->checkRedirectSafety($redirect);
        $view = 'login';
        if ($dialog) {
            $view = 'loginDialog';
        }
        return $this->view($view, $redirectData);
    }

    public function loginCaptcha()
    {
        return $this->api->loginCaptchaRaw();
    }

    public function loginPhone()
    {
        $input = InputPackage::buildFromInput();
        $dialog = $input->getInteger('dialog');
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $redirectData = [
            'redirect' => $redirect,
        ];
        if ($dialog) {
            $redirectData['dialog'] = $dialog;
        }
        $this->api->checkRedirectSafety($redirect);
        if (Request::isPost()) {
            $ret = $this->api->loginPhone();
            if ($ret['code']) {
                return Response::send(-1, $ret['msg']);
            }
            if ($dialog) {
                return Response::send(0, '', '', '[js]parent.location.href=' . json_encode($redirect) . ';');
            }
            return Response::send(0, null, null, $redirect);
        }
        $view = 'loginPhone';
        if ($dialog) {
            $view = 'loginPhoneDialog';
        }
        return $this->view($view, $redirectData);
    }

    public function loginPhoneCaptcha()
    {
        return $this->api->loginPhoneCaptchaRaw();
    }

    public function loginPhoneVerify()
    {
        return Response::sendFromGenerate($this->api->loginPhoneVerify());
    }

    public function logout()
    {
        $input = InputPackage::buildFromInput();
        $redirect = $input->getTrimString('redirect', modstart_web_url(''));
        $this->api->checkRedirectSafety($redirect);
        if (modstart_config('ssoClientEnable', false) && modstart_config('ssoClientLogoutSyncEnable', false)) {
            Input::merge(['domainUrl' => Request::domainUrl()]);
            $ret = $this->api->ssoClientLogoutPrepare();
            if ($ret['code']) {
                return Response::send(-1, $ret['msg']);
            }
            Session::put('ssoLogoutRedirect', $redirect);
            return Response::send(0, null, null, $ret['data']['redirect']);
        }
        $ret = $this->api->logout();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        $input = InputPackage::buildFromInput();
        Session::forget('memberUserId');
        $redirect = $input->getTrimString('redirect', modstart_web_url(''));
        return Response::redirect($redirect);
    }

    public function register()
    {
        $input = InputPackage::buildFromInput();
        $dialog = $input->getInteger('dialog');
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $redirectData = [
            'redirect' => $redirect,
        ];
        if ($dialog) {
            $redirectData['dialog'] = $dialog;
        }
        if (Request::isPost()) {
            $ret = $this->api->register();
            if ($ret['code']) {
                if ($input->getTrimString('captcha')) {
                    return Response::send(-1, $ret['msg'], null, '[js]$("[data-captcha]").click()');
                }
                return Response::send(-1, $ret['msg']);
            }
            $url = modstart_web_url('login', $redirectData);
            return Response::send(0, '', '', $url);
        }
        if (modstart_config('Member_RegisterPhoneEnable', false)) {
            $registerDefault = modstart_config('Member_RegisterDefault', 'default');
            if ('phone' == $registerDefault) {
                $force = $input->getBoolean('force', false);
                if (!$force) {
                    return Response::redirect(modstart_web_url('register/phone', $redirectData));
                }
            }
        }
        $view = 'register';
        if ($dialog) {
            $view = 'registerDialog';
        }
        return $this->view($view, $redirectData);
    }

    public function registerPhone()
    {
        $input = InputPackage::buildFromInput();
        $dialog = $input->getInteger('dialog');
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $redirectData = [
            'redirect' => $redirect,
        ];
        if ($dialog) {
            $redirectData['dialog'] = $dialog;
        }
        $this->api->checkRedirectSafety($redirect);
        if (Request::isPost()) {
            $ret = $this->api->registerPhone();
            if ($ret['code']) {
                return Response::send(-1, $ret['msg']);
            }
            return Response::send(0, null, null, $redirect);
        }
        $view = 'registerPhone';
        if ($dialog) {
            $view = 'registerPhoneDialog';
        }
        return $this->view($view, $redirectData);
    }

    public function registerEmailVerify()
    {
        return Response::sendFromGenerate($this->api->registerEmailVerify());
    }

    public function registerPhoneVerify()
    {
        return Response::sendFromGenerate($this->api->registerPhoneVerify());
    }

    public function registerCaptcha()
    {
        return $this->api->registerCaptchaRaw();
    }

    public function registerCaptchaVerify()
    {
        $ret = $this->api->registerCaptchaVerify();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg'], null, '[js]$("[data-captcha]").click()');
        }
        return Response::send(0, $ret['msg']);
    }

    public function retrieve()
    {
        $input = InputPackage::buildFromInput();
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        return $this->view('retrieve', [
            'redirect' => $redirect,
        ]);
    }

    public function retrievePhone()
    {
        $input = InputPackage::buildFromInput();
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        if (Request::isPost()) {
            $ret = $this->api->retrievePhone();
            if ($ret['code']) {
                return Response::send(-1, $ret['msg']);
            }
            return Response::send(0, $ret['msg'], null, modstart_web_url('retrieve') . '/reset?redirect=' . urlencode($redirect));
        }
        return $this->view('retrievePhone', [
            'redirect' => $redirect,
        ]);
    }

    public function retrievePhoneVerify()
    {
        return Response::sendFromGenerate($this->api->retrievePhoneVerify());
    }

    public function retrieveEmail()
    {
        $input = InputPackage::buildFromInput();
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        if (Request::isPost()) {
            $ret = $this->api->retrieveEmail();
            if ($ret['code']) {
                return Response::send(-1, $ret['msg']);
            }
            return Response::send(0, $ret['msg'], null, modstart_web_url('retrieve') . '/reset?redirect=' . urlencode($redirect));
        }
        return $this->view('retrieveEmail', [
            'redirect' => $redirect,
        ]);
    }

    public function retrieveEmailVerify()
    {
        return Response::sendFromGenerate($this->api->retrieveEmailVerify());
    }

    public function retrieveCaptcha()
    {
        return $this->api->retrieveCaptchaRaw();
    }

    public function retrieveReset()
    {
        $input = InputPackage::buildFromInput();
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        if (Request::isPost()) {
            $ret = $this->api->retrieveReset();
            if ($ret['code']) {
                return Response::send(-1, $ret['msg']);
            }
            return Response::send(0, $ret['msg'], null, $redirect);
        }
        $ret = $this->api->retrieveResetInfo();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        return $this->view('retrieveReset', [
            'redirect' => $redirect,
            'memberUser' => $ret['data']['memberUser'],
        ]);
    }

    public function oauthLogin($oauthType = null)
    {
        $input = InputPackage::buildFromInput();
        $view = $input->getBoolean('view', false);
        if ($view) {
            Session::put('oauthLoginView', true);
        }
        $redirect = $input->getTrimString('redirect', modstart_web_url('member'));
        $this->api->checkRedirectSafety($redirect);
        $callback = Request::domainUrl() . '/oauth_callback_' . $oauthType;
        $ret = $this->api->oauthLogin($oauthType, $callback);
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        Session::put('oauthRedirect', $redirect);
        return Response::redirect($ret['data']['redirect']);
    }

    public function oauthCallback($oauthType = null)
    {
        $callback = Request::domainUrl() . '/oauth_callback_' . $oauthType;
        $ret = $this->api->oauthCallback($oauthType, $callback);
        if ($ret['code']) {
            return Response::sendFromGenerate($ret);
        }
        $view = Session::get('oauthLoginView', false);
        Session::forget('oauthLoginView');
        if ($view) {
            $redirect = Session::get('oauthRedirect', modstart_web_url('member'));
            $oauthUserInfo = Session::get('oauthUserInfo');
            Session::put('oauthViewOpenId_' . $oauthType, $oauthUserInfo['openid']);
            return Response::redirect($redirect);
        }
        return Response::redirect(Request::domainUrl() . '/oauth_bind_' . $oauthType);
    }

    public function oauthBind($oauthType = null)
    {
        $input = InputPackage::buildFromInput();
        $redirect = Session::get('oauthRedirect');
        if (empty($redirect)) {
            $redirect = $input->getTrimString('redirect');
        }
        if (empty($redirect)) {
            $redirect = modstart_web_url('member');
        }
        $this->api->checkRedirectSafety($redirect);
        if (Request::isPost()) {
            $ret = $this->api->oauthBind($oauthType);
            if ($ret['code']) {
                if ($input->getTrimString('captcha')) {
                    return Response::send(-1, $ret['msg'], null, '[js]$("[data-captcha]").click()');
                }
                return Response::send(-1, $ret['msg']);
            }
            Session::forget('oauthRedirect');
            return Response::send(0, $ret['msg'], null, $redirect);
        }
        $oauthUserInfo = Session::get('oauthUserInfo', []);
        $ret = $this->api->oauthTryLogin($oauthType);
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        if ($ret['data']['memberUserId'] > 0) {
            Session::forget('oauthRedirect');
            return Response::redirect($redirect);
        }
        if (MemberUser::isLogin()) {
            $ret = $this->api->oauthBind($oauthType);
            if ($ret['code']) {
                return Response::send(-1, $ret['msg']);
            }
            Session::forget('oauthRedirect');
            return Response::send(0, '绑定成功', null, $redirect);
        }
        return $this->view('oauthBind', [
            'oauthUserInfo' => $oauthUserInfo,
            'redirect' => $redirect,
        ]);
    }

    public function oauthBindCaptcha()
    {
        return $this->api->oauthBindCaptchaRaw();
    }

    public function oauthBindCaptchaVerify()
    {
        $ret = $this->api->oauthBindCaptchaVerify();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg'], null, '[js]$("[data-captcha]").click()');
        }
        return Response::send(0, $ret['msg']);
    }

    public function oauthBindEmailVerify()
    {
        return Response::sendFromGenerate($this->api->oauthBindEmailVerify());
    }

    public function oauthBindPhoneVerify()
    {
        return Response::sendFromGenerate($this->api->oauthBindPhoneVerify());
    }

    public function oauthProxy()
    {
        return $this->view('oauthProxy');
    }

    public function ssoClient()
    {
        $ret = $this->api->ssoClient();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        $redirect = Session::get('ssoClientRedirect', modstart_web_url('member'));
        return Response::send(0, null, null, $redirect);
    }

    public function ssoServer()
    {
        $input = InputPackage::buildFromInput();
        $ret = $this->api->ssoServer();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        $serverSuccessUrl = '/sso/server_success?' . http_build_query([
                'client' => $input->getTrimString('client'),
                'domainUrl' => Request::domainUrl(),
            ]);
        if ($ret['data']['isLogin']) {
            return Response::send(0, null, null, $serverSuccessUrl);
        }
        return Response::send(0, null, null, modstart_web_url('login') . '?' . http_build_query(['redirect' => $serverSuccessUrl]));
    }

    public function ssoServerSuccess()
    {
        $ret = $this->api->ssoServerSuccess();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        return Response::send(0, null, null, $ret['data']['redirect']);
    }

    public function ssoServerLogout()
    {
        $input = InputPackage::buildFromInput();
        $ret = $this->api->ssoServerLogout();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        $redirect = $input->getTrimString('redirect', modstart_web_url(''));
        return Response::send(0, null, null, $redirect);
    }

    public function ssoClientLogout()
    {
        $ret = $this->api->ssoClientLogout();
        if ($ret['code']) {
            return Response::send(-1, $ret['msg']);
        }
        $redirect = Session::get('ssoLogoutRedirect', modstart_web_url(''));
        return Response::send(0, null, null, $redirect);
    }
}
