<?php

namespace App\Http\Controllers;

use DB;
use Input;
use Config;

class ThirdPartyLoginController extends CommonController
{

    protected $curlMethod = 'GET';

    protected $accessToken = '';

    public function generateWeiboUrl()
    {
        $this->type = 'weibo';

        return $this->generateUrl();
    }

    public function weiboCallback()
    {
        $this->type = 'weibo';
        // 获取 open id
        $openId = $this->getOpenId();

        $result = $this->hasOpenId($openId);
        if ($result) {
            return 'See open id '.$result;
        }

        $user = $this->fetchUser($openId);
        $avatarUrl = $user->avatar_hd ? $user->avatar_hd : $user->avatar_large;

        $tmpToken = MultiplexController::temporaryToken();

        $this->storeOpenId($openId, $tmpToken);

        return 'QueryString ?avatar_url='.$avatarUrl.'&token='.$tmpToken;
    }

    protected function getOpenId()
    {
        $this->serviceConfig = Config::get('services.'.$this->type);

        $code = Input::get('code');

        switch ($this->type) {
            case 'weibo':
                $this->curlUrl = 'https://api.weibo.com/oauth2/access_token?client_id='.
                    $this->serviceConfig['AppId'].'&client_secret='.
                    $this->serviceConfig['AppSecret'].'&grant_type=authorization_code&redirect_uri='.
                    urlencode($this->serviceConfig['CallbackUrl']).'&code='.$code;

                $this->curlMethod = 'POST';
                break;
            case 'qq':
                return $this->getQqOpenId();
                break;
            case 'weixin':
                $this->curlUrl = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid='
                    .$this->serviceConfig['AppId'].'&secret='
                    .$this->serviceConfig['AppSecret'].'&code='
                    .$code.'&grant_type=authorization_code';
                break;
            default:
                # code...
                break;
        }

        $outcome = json_decode($this->curlOperate());
        $this->accessToken = $outcome->access_token;

        return ($this->type == 'weibo') ? $outcome->uid : $outcome->openid;
    }

    protected function fetchUser($openId)
    {
        switch ($this->type) {
            case 'weibo':
                $this->curlUrl    = 'https://api.weibo.com/2/users/show.json?access_token='.$this->accessToken.'&uid='.$openId;
                $this->curlMethod = 'GET';
                break;
            case 'qq':
                $this->curlUrl = 'https://graph.qq.com/user/get_user_info?access_token='.
                    $this->accessToken.'&openid='.
                    $openId.'&appid='.$this->serviceConfig['AppId'];
                break;
            case 'weixin':
                $this->curlUrl = 'https://api.weixin.qq.com/sns/userinfo?access_token='.
                    $this->accessToken.'&openid='.
                    $openId.'&lang=zh_CN';
                break;
            default:
                # code...
                break;
        }

        return json_decode($this->curlOperate());
    }

    /**
     * 公共的 curl 操作
     *
     * @return object
     *
     * @throws \App\Exceptions\AuthorizationEntryException
     */
    protected function curlOperate()
    {
        // curl
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->curlUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $this->curlMethod,
            CURLOPT_SSL_VERIFYPEER => false,
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            echo "cURL Error #:" . $err;
        } else {
            return $response;
        }
    }

    /**
     * 检查第三方登录的 open id 是否存在
     *
     * @param  string  $openId
     * @return string|false
     */
    protected function hasOpenId($openId)
    {
        $exist = DB::connection('mongodb')->collection('user')
            ->where('addition.open_id', $openId)
            ->first();

        if ($exist === null) {
            return false;
        }

        return $exist['addition']['token'];
    }

    /**
     * 存储第三方登录的 open id
     *
     * @param  string $openId   [description]
     * @param  string $tmpToken [description]
     * @return void
     */
    protected function storeOpenId($openId, $tmpToken)
    {
        $user = DB::connection('mongodb')->collection('user');

        $insertData = [
            'created_at' => date('Y-m-d H:i:s'),
            'addition' => array(
                    'open_id' => $openId,
                    'token'   => $tmpToken,
                ),
        ];

        $user->insert($insertData);
    }

    public function generateQqUrl()
    {
        $this->type = 'qq';

        return $this->generateUrl();
    }

    public function qqCallback()
    {
        if (Input::get('state') !== 'test') {
            // todo
            return;
        }

        $this->type = 'qq';

        $openId  = $this->getOpenId();

        $result = $this->hasOpenId($openId);
        if ($result) {
            return 'See open id '.$result;
        }

        $user = $this->fetchUser($openId);

        $avatarUrl = $user->figureurl_qq_2 ? $user->figureurl_qq_2 : $user->figureurl_2;

        $tmpToken = MultiplexController::temporaryToken();

        $this->storeOpenId($openId, $tmpToken);

        return 'QueryString ?avatar_url='.$avatarUrl.'&token='.$tmpToken;
    }

    /**
     * 获取 qq 第三方登录的 open id
     *
     * @return string
     */
    protected function getQqOpenId()
    {
        $this->curlUrl = 'https://graph.qq.com/oauth2.0/token?grant_type=authorization_code&client_id='.
            $this->serviceConfig['AppId'].'&client_secret='.
            $this->serviceConfig['AppSecret'].'&code='.
            Input::get('code').'&redirect_uri='.
            urlencode($this->serviceConfig['CallbackUrl']);

        $outcome = $this->curlOperate();

        parse_str($outcome, $arr);

        $this->accessToken = $arr['access_token'];
        $this->curlUrl = 'https://graph.qq.com/oauth2.0/me?access_token='.$this->accessToken;
        $str = $this->curlOperate();
        $start = strpos($str, '{');
        $length = strpos($str, '}') - $start + 1;
        $jsonStr = substr($str, $start, $length);

        return json_decode($jsonStr)->openid;
    }

    /**
     * 生成微信第三方登录 url
     *
     * @return string
     */
    public function generateWeixinUrl()
    {
        $this->type = 'weixin';

        return $this->generateUrl();
    }

    /**
     * 第三授权页面 url
     *
     * @param  string $type 第三方类型
     * @return string
     */
    public function redirectUrl($type)
    {
        $this->type = $type;

        return $this->generateUrl();
    }

    protected function generateUrl()
    {
        $config = Config::get('services.'.$this->type);

        switch ($this->type) {
            case 'weibo':
                $url = 'https://api.weibo.com/oauth2/authorize?client_id='.
                    $config['AppId'].'&redirect_uri='.
                    urlencode($config['CallbackUrl']).'&response_type=code';
                break;
            case 'qq':
                $url = 'https://graph.qq.com/oauth2.0/authorize?response_type=code&client_id='.
                    $config['AppId'].'&redirect_uri='.
                    urlencode($config['CallbackUrl']).'&state=test';
                break;
            case 'weixin':
                $url = 'https://open.weixin.qq.com/connect/oauth2/authorize?appid='.
                    $config['AppId'].'&redirect_uri='.
                    urlencode($config['CallbackUrl']).'&response_type=code&scope=snsapi_userinfo&state=STATE#wechat_redirect';
                break;
            default:
                # code...
                break;
        }

        return $url;
    }

    public function weixinCallback()
    {
        if (Input::get('state') !== 'STATE') {
            // todo
            return;
        }

        $this->type = 'weixin';

        $openId = $this->getOpenId();

        $result = $this->hasOpenId($openId);
        if ($result) {
            return 'See open id '.$result;
        }

        // 拉取第三方用户信息
        $user = $this->fetchUser($openId);
        $avatarUrl = $user->headimgurl;

        $tmpToken = MultiplexController::temporaryToken();
        $this->storeOpenId($openId, $tmpToken);

        return 'QueryString ?avatar_url='.$avatarUrl.'&token='.$tmpToken;
    }

    /**
     * 根据 token 获取用户的登录口令
     *
     * @return array
     */
    public function entry()
    {
        $token = self::validateToken();

        $exist = DB::connection('mongodb')->collection('user')
            ->where('addition.token', $token)
            ->first();

        if ($exist === null) {
            throw new ValidationException('无效的 token');
        }

        return $exist['entry'];
    }

}