<?php

namespace App\Http\Controllers;

use DB;
use Hash;
use Input;
use Response;
use App\User;
use Validator;
use Illuminate\Http\Request;
use App\Exceptions\ValidationException;
use LucaDegasperi\OAuth2Server\Authorizer;

class UserController extends CommonController
{

    protected $email = '';

    public function __construct(Authorizer $authorizer)
    {
        parent::__construct($authorizer);
        $this->middleware('oauth', ['except' => 'store']);
        $this->middleware('disconnect:mongodb', ['only' => ['modify', 'notice', 'removeNotice']]);
        $this->middleware('oauth.checkClient', ['only' => 'store']);
        $this->middleware('validation');
    }

    private static $_validate = [
        'store' => [
            'email' => 'required|email|unique:user',
            'password' => 'required|min:6|confirmed',
        ],
    ];

    /**
     * 用户注册
     *
     */
    public function store()
    {
        $password = request('password');

        $avatarUrl = $this->getAvatarUrl();

        $email = request('email');

        $insertData = [
            'password'   => bcrypt($password),
            'avatar_url' => $avatarUrl,
            'email'      => $email,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $user = DB::collection('user');

        $insertId = $user->insertGetId($insertData);

        // store the thrid party user info
        $this->storeThirdPartyUser($email, $password);

        return $user->find($insertId);
    }

    protected function getAvatarUrl()
    {
        $avatarUrl = '/uploads/images/avatar/default.png';

        if (!Input::has('avatar_url')) {
            return $avatarUrl;
        }

        $avatar_url = Input::get('avatar_url');

        if (preg_match('#^http(s)?://#', $avatar_url) === 1) {
            $avatarUrl = $avatar_url;
        }

        return $avatarUrl;
    }

    /**
     * store the third party user info
     *
     * @param  string $username 登录名
     * @param  string $password 登录密码
     * @return void
     */
    protected function storeThirdPartyUser($username, $password)
    {
        if (!Input::has('token')) {
            return;
        }

        $token = Input::get('token');

        if (strlen($token) !== 30) {
            return;
        }

        $updateData = [
            'entry' => array(
                    'username' => $username,
                    'password' => $password,
                )
        ];
        DB::collection('user')->where('addition.token', $token)
            ->update($updateData);
    }

    public function show()
    {
        $uid = $this->authorizer->getResourceOwnerId();

        return $this->dbRepository('mongodb', 'user')
            ->select('avatar_url', 'email', 'gender', 'display_name', 'company')
            ->find($uid);
    }

    public function logout()
    {
        $oauthAccessToken = DB::table('oauth_access_tokens');

        $oauthAccessToken->where('id', $this->accessToken)->delete();

        return Response::make('', 204);
    }

    public function myComment()
    {
        $uid = $this->authorizer->getResourceOwnerId();

        $this->models['article_comment'] = $this->dbRepository('mongodb', 'article_comment');
        $commentModel = $this->models['article_comment']
            ->where('user._id', $uid)
            ->orderBy('created_at', 'desc');

        MultiplexController::addPagination($commentModel);

        $data = $commentModel->get();

        return $this->handleCommentResponse($data);
    }

    public function myStar()
    {
        $uid = $this->authorizer->getResourceOwnerId();

        $user = $this->dbRepository('mongodb', 'user')->find($uid);

        $articleIds = array();
        if (array_key_exists('starred_articles', $user)) {
            $articleIds = $user['starred_articles'];
        } else {
            return [];
        }

        $articleModel = $this->article()
            ->whereIn('article_id', $articleIds);

        MultiplexController::addPagination($articleModel);

        return $articleModel->get();
    }

    public function myInformation()
    {
        $uid = $this->authorizer->getResourceOwnerId();

        $model = $this->dbRepository('mongodb', 'information')
            ->where('content.comment.user._id', $uid)
            ->orderBy('created_at', 'desc');

        // 增加数据分页
        MultiplexController::addPagination($model);

        return $model->get();
    }

    /**
     * 修改用户信息前的校验
     *
     * @param  string $uid 用户id
     * @return void
     */
    protected function prepareModify($uid)
    {
        $validator = Validator::make(Input::all(), [
            // mongodb 强制唯一规则忽略指定的 ID
            'email'  => 'email|unique:user,email,'.$uid.',_id',
            // mongodb: config database.php connections.mongodb
            // _id: 指定的 ID (指定主键) 名称
            // 'email'  => 'email|unique:mongodb.user,email,'.$uid.',_id',
            'gender' => 'in:男,女',
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->all());
        }
    }

    public function modify()
    {
        $uid = $this->authorizer->getResourceOwnerId();

        $this->email = $this->dbRepository('mongodb', 'user')
            ->where('_id', $uid)
            ->pluck('email');

        $this->prepareModify($uid);

        $user = User::find($uid);

        $allowedFields = ['avatar_url', 'display_name', 'gender', 'email', 'company'];

        array_walk($allowedFields, function ($item) use ($user, $uid) {
            $v = Input::get($item);
            if ($v && $item !== 'avatar_url') {
                $user->$item = $v;
            }
            if ($item === 'email' && Input::has('email')) {
                $this->updateThirdParty($this->email, Input::get('email'));
            }
            if ($item === 'avatar_url' && Input::hasFile('avatar_url')) {
                // todo
                // $user->avatar_url = MultiplexController::uploadAvatar($uid);
            }
        });

        $user->save();

        return $this->dbRepository('mongodb', 'user')->find($uid);
    }

    protected function updateThirdParty($rawEmail, $newEmail)
    {
        $exist = $this->dbRepository('mongodb', 'user')
            ->where('entry.username', $rawEmail)
            ->first();

        if ($exist === null) {
            return;
        }

        $updateData = [
            'entry.username' => $newEmail,
        ];

        $this->dbRepository('mongodb', 'user')
            ->where('entry.username', $rawEmail)
            ->update($updateData);
    }

    public function notice()
    {
        $uid = $this->authorizer->getResourceOwnerId();

        $exist = $this->dbRepository('mongodb', 'information')
            ->where('unread', $uid)
            ->exists();

        return ['new_information' => $exist];
    }

    public function removeNotice()
    {
        $uid = $this->authorizer->getResourceOwnerId();

        $this->dbRepository('mongodb', 'information')
            ->where('unread', $uid)
            ->update(['unread' => '0']);

        return Response::make('', 204);
    }

}