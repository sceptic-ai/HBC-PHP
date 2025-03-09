<?php

namespace app\modules\v1\controllers;

use app\components\cache\MyGameCache;
use app\components\cache\MyRoomCache;
use app\components\exception\GameCardException;
use app\components\exception\MyGameException;
use app\models\Card;
use app\models\GameCard;
use app\models\History;
use app\models\HistoryLog;
use app\models\HistoryPlayer;
use app\models\MyGame;
use app\models\MyRoom;
use app\models\Room;
use app\models\RoomPlayer;
use Yii;
use app\models\Game;

class MyGameController extends MyActiveController
{
    public $roomId;
    public $isHost;
    public $hostPlayer;
    public $guestPlayer;

    public $game;

    public function init(){
        $this->modelClass = Game::className();
        parent::init();
    }

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        return $behaviors;
    }

    /**
     * @apiDefine ParamAuthToken
     *
     * @apiParam {string} authToken 身份认证的token
     */

    /**
     * @apiDefine GroupMyGame
     *
     * 玩家对应的游戏
     */

    public function actionStart(){
        list($isInRoom, $roomId) = MyRoom::isIn();
        # error: 不在房间中
        if(!$isInRoom) {
            MyGameException::t('do_start_not_in_room');
        }
        # error：游戏已经开始
        if(Game::isPlaying($roomId)) {
            MyGameException::t('do_start_game_has_started');
        }

        $room =  Room::getOne($roomId, false); //MyRoom:isIn() 已经对Room做过检查
        $hostPlayer = $room->hostPlayer;
        $guestPlayer = $room->guestPlayer;

        # error：游戏玩家错误
        if(!$hostPlayer || !$guestPlayer) {
            MyGameException::t('do_start_wrong_players');
        }
        # error：操作人不是主机玩家
        if($hostPlayer->user->id != Yii::$app->user->id) {
            MyGameException::t('do_start_not_host_player');
        }
        # error：客机玩家没有准备
        if($guestPlayer->is_ready != 1) {
            MyGameException::t('do_start_guest_player_not_ready');
        }

        # 开始创建游戏
        Game::createOne($roomId);

        # 初始化牌库
        GameCard::initLibrary($roomId);

        # 主机/客机玩家 各模五张牌
        for($i = 0; $i < 5; $i++){
            GameCard::drawCard($roomId,true);
            GameCard::drawCard($roomId,false);
        }

        # 创建游戏History
        History::createOne($roomId);

        # 记录日志
        HistoryLog::record($roomId, 'start');

        #清除主客玩家的房间缓存
        MyRoomCache::clear($hostPlayer->user_id);
        MyRoomCache::clear($guestPlayer->user_id);

    }

    /**
     * @api {post} /my-game/end 结束游戏
     * @apiName End
     * @apiGroup GroupMyGame
     *
     * @apiVersion 1.0.0
     *
     * @apiUse ParamAuthToken
     */

    public function actionEnd(){
        # 操作前的检查
        $this->checkDo();

        // TODO 暂时主机玩家可以强制结束游戏
        # 操作人不是主机玩家
        if($this->hostPlayer->user_id != Yii::$app->user->id){
            MyGameException::t('do_end_not_host_player');
        }

        # 执行结束游戏
        Game::deleteOne($this->roomId);

        # 记录结束日志
        HistoryLog::record($this->roomId, 'end');

        # 日志状态修改为结束
        History::deleteOne($this->roomId);

        #清除主客玩家的游戏缓存
        MyGameCache::clear($this->hostPlayer->user_id);
        MyGameCache::clear($this->guestPlayer->user_id);

        #清除主客玩家的房间缓存
        MyRoomCache::clear($this->hostPlayer->user_id);
        MyRoomCache::clear($this->guestPlayer->user_id);
    }

    /**
     * @api {get} /my-game/info 获取游戏信息
     * @apiName Info
     * @apiGroup GroupMyGame
     *
     * @apiVersion 1.0.0
     *
     * @apiUse ParamAuthToken
     * @apiParam {string=all,simple} mode=all 获取模式
     * @apiParam {boolean=false,true} force=false 是否强制刷新
     *
     */

    public function actionInfo(){
        $mode = Yii::$app->request->get('mode','all');
        $force = !!Yii::$app->request->get('force',false);
        if(!$force) {
            if(MyGameCache::isNoUpdate(Yii::$app->user->id)) {//存在则不更新游戏信息
                return ['noUpdate'=>true];
            }
        }

        #判断是否在游戏中， 获得房间ID
        list($isInRoom, $roomId) = MyRoom::isIn();
        #不在房间中 抛出异常
        if(!$isInRoom) {
            return ['roomId' => -1];
        }

        $isPlaying = Game::isPlaying($roomId);
        $data = [];
        $data['isPlaying'] = $isPlaying;
        $data['roomId'] = $roomId;
        if(!$isPlaying) {
            return $data;
        }
        if ($mode == 'all') {
            $game = Game::getOne($roomId);

            $data['game'] = [
                'roundNum' => $game->round_num,
                'roundPlayerIsHost' => $game->round_player_is_host == 1,
            ];
            $data['card'] = Game::getCardInfo($game->room_id);
            $data['log'] = HistoryLog::getList($game->room_id);
            $data['game']['lastUpdated'] = HistoryLog::getLastUpdate($game->room_id);
            MyGameCache::set(Yii::$app->user->id);
        }
        return $data;
    }

    /*
     * 玩家操作前的验证
     */
    private function checkDo(){
        # 判断是否在游戏中， 获得房间ID
        list($isInRoom, $roomId) = MyRoom::isIn();
        # 不在房间中 抛出异常
        if(!$isInRoom) {
            MyGameException::t('do_operation_not_in_room');
        }

        # 不在游戏中
        if(!Game::isPlaying($roomId)) {
            MyGameException::t('do_operation_not_in_game');
        }

        $room =  Room::getOne($roomId);
        $hostPlayer = $room->hostPlayer;
        $isHost = $hostPlayer->user_id == Yii::$app->user->id;  //操作玩家是否是主机玩家

        $game = Game::getOne($roomId);
        $roundPlayerIsHost = $game->round_player_is_host == 1; // 当前回合玩家是否是主机玩家

        if($roundPlayerIsHost != $isHost){ // 判断是不是当前玩家操作的回合
            MyGameException::t('do_operation_not_player_round');
        }

        $this->roomId = $roomId;
        $this->isHost = $isHost;
        $this->hostPlayer = $room->hostPlayer;
        $this->guestPlayer = $room->guestPlayer;

        $this->game = $game;
    }

    /*
     * 玩家操作后的验证, 不通过要进入结束游戏流程
     */
    private function checkDone(){
        $game = Game::getOne($this->roomId, false);

        # 剩余机会数 为 0
        if($game->chance_num == 0) {
            return false;
        }

        # 总分已达到 25分
        if($game->score == 25) {
            return false;
        }

        # 双方都无手牌
        $handsCount = (int) GameCard::find()->where(['room_id'=>$this->roomId, 'type'=>[GameCard::TYPE_HOST_HANDS, GameCard::TYPE_GUEST_HANDS]])->count();
        if($handsCount == 0) {
            return false;
        }

        return true;
    }

    /**
     * @api {post} /my-game/do-discard 弃牌操作
     * @apiName DoDiscard
     * @apiGroup GroupMyGame
     *
     * @apiVersion 1.0.0
     *
     * @apiUse ParamAuthToken
     * @apiParam {int=0,1,2,3,4} cardSelectOrd 所选手牌的排序
     */
    public function actionDoDiscard(){
        $typeOrd = Yii::$app->request->post('cardSelectOrd');

        # 操作前的检查
        $this->checkDo();

        # 根据isHost，确定卡牌类型是主机手牌还是客机手牌
        $cardType = $this->isHost ? GameCard::TYPE_HOST_HANDS : GameCard::TYPE_GUEST_HANDS;

        # 找到所选择的牌
        $card = GameCard::getOne($this->roomId, $cardType, $typeOrd);

        # 将牌移入弃牌堆
        GameCard::discard($this->roomId, $card->ord);

        # 记录操作日志
        HistoryLog::record($this->roomId, 'discard', ['cardOrd'=>$card->ord]);

        # 操作后的检查
        if($this->checkDone()){
            # 通过则进入结算流程

            # 恢复一个提示数
            Game::recoverCue($this->roomId);

            # 由于打出一张牌，将剩余的手牌做牌序移动
            GameCard::moveHandCardsByLackOfCard($this->roomId, $this->isHost, $typeOrd);

            # 摸牌
            GameCard::drawCard($this->roomId, $this->isHost);

            # 进入下一个回合
            Game::nextRound($this->roomId);

        } else {
            # 不通过则进入结束游戏流程

            # 执行结束游戏
            Game::deleteOne($this->roomId);

            # 记录结束日志
            HistoryLog::record($this->roomId, 'end');

            # 日志状态修改为结束
            History::deleteOne($this->roomId);
        }

        MyGameCache::clear($this->hostPlayer->user_id);
        MyGameCache::clear($this->guestPlayer->user_id);
    }

    /**
     * @api {post} /my-game/do-play 打牌操作
     * @apiName DoPlay
     * @apiGroup GroupMyGame
     *
     * @apiVersion 1.0.0
     *
     * @apiUse ParamAuthToken
     * @apiParam {int=0,1,2,3,4} cardSelectOrd 所选手牌的排序
     */
    public function actionDoPlay(){
        $typeOrd = Yii::$app->request->post('cardSelectOrd');

        # 操作前的检查 并初始化
        $this->checkDo();

        # 根据isHost，确定类型是主机手牌还是客机手牌
        $cardType = $this->isHost ? GameCard::TYPE_HOST_HANDS : GameCard::TYPE_GUEST_HANDS;

        # 找到所选择的牌
        $card = GameCard::getOne($this->roomId, $cardType, $typeOrd);

        # 找出燃放成功（桌面上）每种花色最大的数字
        $cardsSuccessTop = GameCard::getCardsSuccessTop($this->roomId);

        # 当前所选卡牌花色对应的最大数字
        $colorTopNum = $cardsSuccessTop[$card->color];

        # 所选卡牌的数字值
        $num = Card::$numbers[$card->num];

        # 所选数字 = 最大数字 + 1 即打出成功
        if($colorTopNum + 1 == $num){
            # 出牌成功 置入桌面上
            GameCard::success($this->roomId, $card->ord);

            # 游戏分数+1
            Game::addScore($this->roomId);

            # 恢复一个提示数
            Game::recoverCue($this->roomId);

            $playSuccess = true;
        }else{
            # 出牌失败 置入弃牌堆
            GameCard::discard($this->roomId, $card->ord);

            # 消耗一次机会
            Game::useChance($this->roomId);

            $playSuccess = false;
        }




        # 操作后的检查
        if($this->checkDone()){
            # 由于打出一张牌，将剩余的手牌做牌序移动
            GameCard::moveHandCardsByLackOfCard($this->roomId, $this->isHost, $typeOrd);

            # 摸牌
            GameCard::drawCard($this->roomId, $this->isHost);

            # 进入下一个回合
            Game::nextRound($this->roomId);

            # 记录日志
            HistoryLog::record($this->roomId, 'play', ['cardOrd'=>$card->ord, 'playSuccess'=>$playSuccess]);
        }else{
            # 不通过则进入结束游戏流程

            # 记录成功日志
            HistoryLog::record($this->roomId, 'play', ['cardOrd'=>$card->ord, 'playSuccess'=>false]);

            # 执行结束游戏
            Game::deleteOne($this->roomId);

            # 记录结束日志
            HistoryLog::record($this->roomId, 'end');

            # 日志状态修改为结束
            History::deleteOne($this->roomId);
        }

        MyGameCache::clear($this->hostPlayer->user_id);
        MyGameCache::clear($this->guestPlayer->user_id);

    }

    /**
     * @api {post} /my-game/do-cue 提示操作
     * @apiName DoCue
     * @apiGroup GroupMyGame
     *
     * @apiVersion 1.0.0
     *
     * @apiUse ParamAuthToken
     * @apiParam {int=0,1,2,3,4} cardSelectOrd 所选对手手牌的排序
     * @apiParam {string="num","color"} cueType 提示类型
     */
    public function actionDoCue(){
        $typeOrd = Yii::$app->request->post('cardSelectOrd');
        $cueType = Yii::$app->request->post('cueType');

        $this->checkDo();

        # 根据isHost，确定卡牌类型是主机手牌还是客机手牌
        $cardType = !$this->isHost ? GameCard::TYPE_HOST_HANDS : GameCard::TYPE_GUEST_HANDS;

        # 找到所选择的牌
        $card = GameCard::getOne($this->roomId, $cardType, $typeOrd);

        # 找到提示的卡牌排序列表
        $cueCardsOrd = GameCard::cue($this->roomId, $card, $cueType);

        # 消耗一个提示数
        Game::useCue($this->roomId);

        # 进入下一个回合
        Game::nextRound($this->roomId);

        # 记录日志
        HistoryLog::record($this->roomId, 'cue', ['cardOrd'=>$card->ord, 'cueType'=>$cueType, 'cueCardsOrd'=>$cueCardsOrd ]);

        MyGameCache::clear($this->hostPlayer->user_id);
        MyGameCache::clear($this->guestPlayer->user_id);
    }


    /**
     * @api {post} /my-game/auto-play 自动打牌
     * @apiName AutoPlay
     * @apiGroup GroupMyGame
     *
     * @apiVersion 1.0.0
     *
     * @apiUse ParamAuthToken
     *
     *
     */
    /*
     *
     * 自动打牌（挂机）
     *
     * 1. 有剩余提示数 则随机提示一张牌的 颜色或者数字
     *
     * 2. 没有剩余提示数 则随机丢弃一张牌
     *
     */
    public function actionAutoPlay(){

        $success = false;
        $msg = '';
        $data = [];
        $user_id = Yii::$app->user->id;
        $room_player = RoomPlayer::find()->where(['user_id'=>$user_id])->one();
        if($room_player){
            $game = Game::find()->where(['room_id'=>$room_player->room_id,'status'=>Game::STATUS_PLAYING])->one();
            if($game){
                if($game->round_player_is_host==$room_player->is_host){
                    if($game->cue_num>0){

                        $rand = $room_player->is_host == 1 ? rand(5,9) : rand(0,4);

                        return Game::cue($rand,rand(0,1) ? 'num':'color');

                    }else{

                        $rand = $room_player->is_host != 1 ? rand(5,9) : rand(0,4);

                        return Game::discard($rand);

                    }
                }else{
                    $msg = '当前不是你的回合';
                }
            }else{
                $msg = '你所在房间游戏未开始/或者有多个游戏，错误';
            }
        }else{
            $msg = '你不在房间中/不止在一个房间中，错误';
        }

        return [$success,$msg,$data];

    }
}
