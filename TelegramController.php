<?php

namespace App\Http\Controllers\Guest;

use App\Services\TelegramService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Utils\Helper;
use App\Services\TicketService;

class TelegramController extends Controller
{
    protected $msg;

    public function __construct(Request $request)
    {
        if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(500, 'authentication failed');
        }
    }

    public function webhook(Request $request)
    {
        $this->msg = $this->getMessage($request->input());
        if (!$this->msg) return;
        try {
            switch($this->msg->message_type) {
                case 'send':
                    $this->fromSend();
                    break;
                case 'reply':
                    $this->fromReply();
                    break;
            }
        } catch (\Exception $e) {
            $telegramService = new TelegramService();
            $telegramService->sendMessage($this->msg->chat_id, $e->getMessage());
        }
    }

    private function fromSend()
    {
        switch($this->msg->command) {
            case '/bind': $this->bind();
                break;
            case '/traffic': $this->traffic();
                break;
            case '/getlatesturl': $this->getLatestUrl();
                break;
            case '/unbind': $this->unbind();
                break;
            case '/checkin': $this->checkin();
                break;
            case '/lucky': $this->lucky();
                break;
            default: $this->help();
        }
    }

    private function fromReply()
    {
        // ticket
        if (preg_match("/[#](.*)/", $this->msg->reply_text, $match)) {
            $this->replayTicket($match[1]);
        }
    }

    private function getMessage(array $data)
    {
        if (!isset($data['message'])) return false;
        $obj = new \StdClass();
        $obj->is_private = $data['message']['chat']['type'] === 'private' ? true : false;
        if (!isset($data['message']['text'])) return false;
        $text = explode(' ', $data['message']['text']);
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = !isset($data['message']['reply_to_message']['text']) ? 'send' : 'reply';
        $obj->text = $data['message']['text'];
        if ($obj->message_type === 'reply') {
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }
        return $obj;
    }

    private function bind()
    {
        $msg = $this->msg;
        if (!$msg->is_private) return;
        if (!isset($msg->args[0])) {
            abort(500, '参数有误，请携带订阅地址发送');
        }
        $subscribeUrl = $msg->args[0];
        $subscribeUrl = parse_url($subscribeUrl);
        parse_str($subscribeUrl['query'], $query);
        $token = $query['token'];
        if (!$token) {
            abort(500, '订阅地址无效');
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, '用户不存在');
        }
        if ($user->telegram_id) {
            abort(500, '该账号已经绑定了Telegram账号');
        }
        $user->telegram_id = $msg->chat_id;
        if (!$user->save()) {
            abort(500, '设置失败');
        }
        $telegramService = new TelegramService();
        $telegramService->sendMessage($msg->chat_id, '绑定成功');
    }

    private function unbind()
    {
        $msg = $this->msg;
        if (!$msg->is_private) return;
        $user = User::where('telegram_id', $msg->chat_id)->first();
        $telegramService = new TelegramService();
        if (!$user) {
            $this->help();
            $telegramService->sendMessage($msg->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $user->telegram_id = NULL;
        if (!$user->save()) {
            abort(500, '解绑失败');
        }
        $telegramService->sendMessage($msg->chat_id, '解绑成功', 'markdown');
    }

    private function help()
    {
        $msg = $this->msg;
        if (!$msg->is_private) return;
        $telegramService = new TelegramService();
        $commands = [
            '/bind 订阅地址 - 绑定你的' . config('v2board.app_name', 'V2Board') . '账号',
            '/traffic - 查询流量信息',
            '/checkin - 每日签到',
            '/lucky - 抽奖',
            '/getlatesturl - 获取最新的' . config('v2board.app_name', 'V2Board') . '网址',
            '/unbind - 解除绑定'
        ];
        $text = implode(PHP_EOL, $commands);
        $telegramService->sendMessage($msg->chat_id, "你可以使用以下命令进行操作：\n\n$text", 'markdown');
    }

    private function traffic()
    {
        $msg = $this->msg;
        if (!$msg->is_private) return;
        $user = User::where('telegram_id', $msg->chat_id)->first();
        $telegramService = new TelegramService();
        if (!$user) {
            $this->help();
            $telegramService->sendMessage($msg->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $balance = $user->balance / 100 ;
        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));
        $text = "🚥流量查询\n———————————————\n当前余额：`{$balance}`元\n计划流量：`{$transferEnable}`\n已用上行：`{$up}`\n已用下行：`{$down}`\n剩余流量：`{$remaining}`";
        $telegramService->sendMessage($msg->chat_id, $text, 'markdown');
    }
    private function checkin()
    {
        $msg = $this->msg;
        if (!$msg->is_private) return;
        $user = User::where('telegram_id', $msg->chat_id)->first();
        $telegramService = new TelegramService();
        if (!$user) {
            $this->help();
            $telegramService->sendMessage($msg->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $lastcheckinat = $user->last_checkin_at ;
        $last = date('Ymd', $lastcheckinat);
        $today = date('Ymd');
        if ($last != $today ) {
            //吱吱提醒
            //下面括号内填写签到的奖励范围，单位MB，例如填写 (1,1024);表示随机奖励1-1024MB
            $randomtraffic = random_int(1,1024);
            $gifttraffic = $randomtraffic * 1024 * 1024;
            $user->transfer_enable += $gifttraffic;
            $gift = Helper::trafficConvert($gifttraffic);
            $user->last_checkin_at = time();
            $user->save();
            $text = "✏️恭喜您签到成功\n获得奖励：`{$gift}`";
        }else{
            $text = "您今天已经签过到了～";
        }
        $telegramService->sendMessage($msg->chat_id, $text, 'markdown');
    }
            //ONEFALL.TOP提醒
            //如果要将时间精确到分钟或秒请在Ymdh后面增加i或is
    private function lucky()
    {
        $msg = $this->msg;
        if (!$msg->is_private) return;
        $user = User::where('telegram_id', $msg->chat_id)->first();
        $telegramService = new TelegramService();
        if (!$user) {
            $this->help();
            $telegramService->sendMessage($msg->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $lastluckyat = $user->last_lucky_at ;
        $last = date('Ymdh', $lastluckyat);//在这里增加
        $today = date('Ymdh'); //和这里
        if ($last != $today ) {
            //吱吱提醒  
            //下面括号内填写抽奖的奖励范围，单位MB，例如填写 (-1024,1024);表示随机奖励-1024到1024MB
            $randomtraffic = random_int(0,888);
            $gifttraffic = $randomtraffic * 1024 * 1024;
            $user->transfer_enable += $gifttraffic;
            $gift = Helper::trafficConvert($gifttraffic);
            $user->last_lucky_at = time();
            $user->save();
            $text = "恭喜您获得奖励：`{$gift}`";
        }else{
            $text = "等一个小时再抽奖呢";
        }
        
        $telegramService->sendMessage($msg->chat_id, $text, 'markdown');
    }
    private function getLatestUrl()
    {
        $msg = $this->msg;
        $user = User::where('telegram_id', $msg->chat_id)->first();
        $telegramService = new TelegramService();
        $text = sprintf(
            "%s的最新网址是：%s",
            config('v2board.app_name', 'V2Board'),
            config('v2board.app_url')
        );
        $telegramService->sendMessage($msg->chat_id, $text, 'markdown');
    }

    private function replayTicket($ticketId)
    {
        $msg = $this->msg;
        if (!$msg->is_private) return;
        $user = User::where('telegram_id', $msg->chat_id)->first();
        if (!$user) {
            abort(500, '用户不存在');
        }
        $ticketService = new TicketService();
        if ($user->is_admin || $user->is_staff) {
            $ticketService->replyByAdmin(
                $ticketId,
                $msg->text,
                $user->id
            );
        }
        $telegramService = new TelegramService();
        $telegramService->sendMessage($msg->chat_id, "#`{$ticketId}` 的工单已回复成功", 'markdown');
        $telegramService->sendMessageWithAdmin("#`{$ticketId}` 的工单已由 {$user->email} 进行回复", true);
    }


}
