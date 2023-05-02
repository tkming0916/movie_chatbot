<?php
/*
 * chat/plain : 超簡易チャットスクリプト for PHP
 *
 * Copyright (c) 2003-2012 confetto. <confetto@s31.xrea.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * The latest version is here: http://confetto.s31.xrea.com/
 * This script is written in japanese, and encoded in UTF-8.
 */

define('CVS_TAG', '$Name: release-3-0 $');
define('RCS_ID', '$Id: chat.php,v 3.4 2012/06/12 16:03:43 confetto Exp $');

/********************************** 初期設定 **********************************/

// 保持する発言の数
define('SAVE_MAX', 10000);

// 情報を伝える発言者名
//  入室、退室を伝えるメッセージの発言者名です。
define('INFORMER_NAME', 'Info');

// データファイルのパス
define('DATA_DIR', './data/');
define('MESSAGE_FILE', DATA_DIR . 'messages.dat.cgi');	// 発言保存ファイル
define('SESSION_FILE', DATA_DIR . 'sessions.dat.cgi');	// 参加者名保存ファイル

// クッキーの名前
define('COOKIE_NAME', 'chat');

// クッキーの有効期限(秒)
define('COOKIE_EXPIRES', 365 * 24 * 60 * 60);	// 365日

// セッションタイムアウト(秒)
define('SESSION_TIMEOUT', 10 * 60);	// 10分

// テンプレートのパス
define('TEMPLATE_DIR', './templates/');
define('START_TEMPLATE', TEMPLATE_DIR . 'start.php');	// 入室前
define('SPEAK_TEMPLATE', TEMPLATE_DIR . 'speak.php');	// 入室/発言/更新時
define('QUIT_TEMPLATE' , TEMPLATE_DIR . 'quit.php');	// 退室時
define('ERROR_TEMPLATE', TEMPLATE_DIR . 'error.php');	// エラー時

// 発言の文字色
$COLORS = array(
	'指定なし' => 'inherit',
	'黒'    => '#000000',
	'茶'    => '#600600',
	'灰'    => '#666666',
	'赤'    => '#c00c00',
	'暗黄'  => '#660660',
	'緑'    => '#060060',
	'青緑'  => '#066066',
	'紺'    => '#006006',
	'青'    => '#00c00c',
	'紫'    => '#606606',
	'明紫'  => '#c0cc0c'
);

// 既定の文字色。
define('DEFAULT_COLOR', $COLORS['指定なし']);

/********************************** ルーチン **********************************/

abstract class ChatData {
	public function __construct() {
		$arguments = func_get_args();
		foreach ($this as $key => $value)
			$this->{$key} = array_shift($arguments);
	}

	public function __get($name) {
		if (!isset($this->{$name}))
			throw new LogicException('Undefined property: ' . $name);

		return $this->{$name};
	}

	/**
	 * 各プロパティの値から、タブ区切りの文字列を生成する。
	 */
	public function serialize() {
		foreach ($this as $property)
			$fields[] = addcslashes($property, "\\\t\n\r");
		return implode("\t", $fields);
	}

	/**
	 * serialize()で生成した文字列から、各プロパティに値を読み込む。
	 *
	 * @param string $string タブ区切りの文字列
	 */
	public function unserialize($string) {
		$fields = explode("\t", $string);
		foreach ($this as $key => $value)
			$this->{$key} = stripcslashes(array_shift($fields));
	}

	/**
	 * 配列に変換する。
	 *
	 * @param array $properties
	 */
	public function toArray($properties) {
		$result = array();
		foreach ($properties as $property)
			$result[$property] = $this->{$property};
		return $result;
	}
}

/******************************************************************************/

abstract class ChatDataList implements IteratorAggregate, Countable {
	protected $list;
	private $fileName;
	private $fp;

	/**
	 * @param string $fileName データを保存するファイル名
	 */
	public function __construct($fileName) {
		$this->fileName = $fileName;
	}

	/**
	 * イテレータを取得する。
	 *
	 * @return Iterator
	 */
	public function getIterator() {
		return new ArrayIterator($this->list);
	}

	/**
	 * データの数を取得する。
	 *
	 * @return integer
	 */
	public function count() {
		return count($this->list);
	}

	/**
	 * ファイルを開き、データを読み込む。
	 *
	 * @param boolean $lock 読み込みだけならFALSE、後にcheckinするならTRUE
	 */
	protected function checkout($lock = TRUE) {
		if (!($this->fp = fopen($this->fileName, $lock ? 'r+' : 'r')))
			throw new RuntimeException('Cannot open file ' . $this->fileName);

		if (!flock($this->fp, $lock ? LOCK_EX : LOCK_SH))
			throw new RuntimeException('Cannot lock file ' . $this->fileName);

		$this->list = array();
		while ($line = fgets($this->fp)) {
			$this->list[] = $this->newData();
			$this->list[count($this->list) - 1]->unserialize(rtrim($line));
		}

		if (!$lock)
			$this->close();
	}

	/**
	 * データへの変更を反映し、ファイルを閉じる。
	 */
	protected function checkin() {
		rewind($this->fp);
		foreach ($this as $data)	// 自身のイテレータを使う
			if (!fwrite($this->fp, $data->serialize() . "\n"))
				throw new RuntimeException('Cannot write file');
		ftruncate($this->fp, ftell($this->fp));
		$this->close();
	}

	/**
	 * ファイルを閉じる。
	 */
	private function close() {
		if (!fclose($this->fp))
			throw new RuntimeException('Cannot close file');
		$this->fp = NULL;
	}

	/**
	 * 新しいデータを生成する。
	 *
	 * @return ChatData 新しい空のデータ
	 */
	abstract protected function newData();
}

/******************************************************************************/

/**
 * セッションデータ。
 */
class ChatSession extends ChatData {
	protected $id;		// セッションID
	protected $time;	// タイムスタンプ
	protected $name;	// 参加者名
	public $lastNum = 0;
	public $color;		// 文字色

	/**
	 * タイムスタンプを更新する。
	 */
	public function update() {
		return $this->time = time();
	}
}

/******************************************************************************/

/**
 * セッションを管理する。
 */
class ChatSessionList extends ChatDataList {
	/**
	 * ファイルを開き、セッション情報を読み込む。
	 *
	 * @param string $file_name セッション情報を保存するファイル名
	 */
	public function __construct($file_name) {
		parent::__construct($file_name);
		$this->checkout();
	}

	/**
	 * セッション情報を保存し、ファイルを閉じる。
	 */
	public function __destruct() {
		$this->checkin();
	}

	/**
	 * 指定された時間、更新されていないセッションを削除する。
	 *
	 * @param integer $timeout タイムアウトまでの秒数
	 * @param callback $iterator 削除される参加者名を引数に取る関数(省略可)
	 */
	public function cleanUp($timeout, $iterator = NULL) {
		foreach ($this->list as $i => $session) {
			if ($timeout < time() - $session->time) {
				if ($iterator)
					call_user_func($iterator, $session->name);
				unset($this->list[$i]);
			}
		}
	}

	/**
	 * 指定されたIDのセッションを取得する。セッションが見つからなければFALSEを返
	 * す。
	 *
	 * @param string $id セッションID
	 * @return ChatSession|FALSE 取得したセッション、もしくはFALSE
	 */
	public function getSession($id) {
		foreach ($this->list as $session)
			if ($session->id === $id)
				return $session;
		return FALSE;
	}

	/**
	 * 新しい参加者を登録する。参加者名が重複したらFALSEを返す。
	 *
	 * @param string $name 参加者名
	 * @return ChatSession|FALSE セッション、もしくはFALSE
	 */
	public function register($name) {
		foreach ($this->list as $session)
			if ($session->name === $name)
				return FALSE;

		$id = md5(uniqid(rand(), true));
		return $this->list[] = new ChatSession($id, time(), $name);
	}

	/**
	 * セッションを削除する。
	 *
	 * @param string $id セッションID
	 */
	public function destroy($id) {
		foreach ($this->list as $i => $session) {
			if ($session->id === $id) {
				unset($this->list[$i]);
				break;
			}
		}
	}

	protected function newData() { return new ChatSession; }
}

/******************************************************************************/

/**
 * 発言データ。
 */
class ChatMessage extends ChatData {
	protected $number;	// 発言番号
	protected $name;	// 発言者名
	protected $message;	// 発言内容
	protected $isInfo;
	protected $color;	// 文字色
	protected $date;	// 日付
}

/******************************************************************************/

/**
 * 発言データをリストで管理する。
 */
class ChatMessageList extends ChatDataList {
	const DATE_FORMAT = 'Y-m-d H:i';
	const TIME_FORMAT = 'G:i';

	private $saveMax;

	/**
	 * @param string $fileName 発言を保存するファイル名
	 * @param integer $saveMax 保存する発言の最大数
	 */
	public function __construct($fileName, $saveMax) {
		parent::__construct($fileName);
		$this->saveMax = $saveMax;
	}

	/**
	 * 発言をファイルに保存する。
	 *
	 * @param string $message 発言内容
	 * @param string $name 発言者名
	 * @param string $isInfo
	 * @param string $color 文字色
	 */
	public function add($message, $name, $isInfo, $color) {
		$this->checkout();
		$number = $this->lastNum() + 1;
		$dtf = $isInfo ? self::DATE_FORMAT : self::TIME_FORMAT;
		$newMessage = new ChatMessage($number, $name, $message,
			$isInfo, $color, gmdate($dtf, time() + 60 * 60 * 9)); // JST
		array_unshift($this->list, $newMessage);
		$this->checkin();
	}

	/**
	 * 最新の番号を取得する。
	 *
	 * @return integer 最新の発言の番号
	 */
	public function lastNum() {
		if (!isset($this->list))
			$this->checkout(FALSE);

		return isset($this->list[0]) ? $this->list[0]->number : 0;
	}

	/**
	 * イテレータを取得する。
	 *
	 * @return Iterator 発言データのイテレータ
	 */
	public function getIterator() {
		if (!isset($this->list))
			$this->checkout(FALSE);

		// LimitIteratorは空のSeekableIteratorをうまく扱えない。
		// 参考: http://bugs.php.net/bug.php?id=49723
		$iterator = $this->list ? parent::getIterator() : new EmptyIterator();
		return new LimitIterator($iterator, 0, $this->saveMax);
	}

	protected function newData() { return new ChatMessage; }
}

/******************************************************************************/

/**
 * 簡易テンプレート。
 * 参考: http://anond.hatelabo.jp/20071030034313
 */
class Template {
	private $context = array();

	/**
	 * テンプレートに値を割り当てる。
	 *
	 * @param string $name テンプレート内の変数名
	 * @param mixed $value 割り当てる値
	 */
	public function assign($name, $value) {
		$this->context[$name] = $value;
	}

	/**
	 * テンプレートを表示する。
	 *
	 * @param string $template テンプレートのファイル名
	 */
	public function display(/*$template*/) {
		extract($this->context);
		require func_get_arg(0);
	}

	/**
	 * テンプレートの出力結果を返す。
	 *
	 * @param string $template テンプレートのファイル名
	 */
	public function output($template) {
		ob_start();
		try {
			$this->display($template);
		} catch (Exception $exception) {
			ob_end_clean();
			throw $exception;
		}
		$result = ob_get_contents();
		ob_end_clean();
		return $result;
	}
}

/******************************************************************************/

class UserError extends RuntimeException {}

class ChatApplication {
	private $messages;
	private $sessions;
	private $session;
	private $template;
	private $lastUserName; #吉野

	public function __construct() {
		$version = preg_match_all('/\d+/', CVS_TAG, $matched) ?
			implode('.', $matched[0]) : '?';

		$this->messages = new ChatMessageList(MESSAGE_FILE, SAVE_MAX);

		$this->sessions = new ChatSessionList(SESSION_FILE);
		$this->sessions->cleanUp(SESSION_TIMEOUT, array($this, 'cleanUp'));

		$this->template = new Template();
		$this->template->assign('version', $version);
		$this->template->assign('messages', $this->messages);
		$this->template->assign('sessions', $this->sessions);

		$sessionID = isset($_POST['sid']) ? $_POST['sid'] :
			(isset($_COOKIE['sid']) ? $_COOKIE['sid'] : NULL);
		$this->session = $this->sessions->getSession($sessionID);
	}

	public function run() {
		try {
			if (isset($_POST['quit'])) {	// 退室時はセッション不要
				$output = $this->quit();
			} else {
				if ($this->session) {	// 有効なセッションがあるなら
					$this->session->update();
					if (isset($_POST['mssg']))	// 発言/更新時
						$output = $this->speak();
					else	// 再入室時
						$output = $this->rejoin();
				} else {
					if (isset($_POST['mssg']))	// 発言/更新時
						throw new UserError('セッションが切れました。');
					elseif (isset($_POST['name']))	// 入室時
						$output = $this->join();
					else	// 入室前
						$output = $this->start();
				}
			}
		} catch (UserError $error) {
			$output = $this->error($error->getMessage());
		} catch (Exception $exception) {
			$output = $this->error("例外が発生しました。\n\n" . $exception);
		}
		echo $output;
	}

	public function cleanUp($name) {
		$message = $name . 'さんはもういないようです。';
		$this->messages->add($message, INFORMER_NAME, TRUE, 'inherit');
	}

	/**
	 * 入室前の画面を表示する。
	 */
	private function start() {
		$cookie = array();
		try {
			$cookie = @unserialize($_COOKIE[COOKIE_NAME]);
		} catch (ErrorException $error) {}
		if (!is_array($cookie))
			$cookie = array();

		$name = isset($cookie['name']) ? $cookie['name'] : '';
		$color = isset($cookie['color']) ? $cookie['color'] : DEFAULT_COLOR;

		$this->headers();
		$this->template->assign('name', $name);
		$this->template->assign('color', $color);
		return $this->template->output(START_TEMPLATE);
	}

	/**
	 * 入室する。
	 */
	private function join() {
		if ($_POST['name'] === '')
			throw new UserError('名前を入力してください。');

		$session = $this->sessions->register($_POST['name']);
		if (!$session)
			throw new UserError('同じ名前の人がいます。名前を変えてください。');

		$message = $_POST['name'] . 'さんが入室しました。';

		$session->lastUserName  = $_POST['name']; # 最後の名前を保存，吉野

		$this->messages->add($message, INFORMER_NAME, TRUE, 'inherit');
		$session->lastNum = $this->messages->lastNum();
		$session->color = $_POST['color'];

		setcookie('sid', $session->id);

		$this->headers();
		$this->template->assign('sessionID', $session->id);
		return $this->template->output(SPEAK_TEMPLATE);
	}

	/**
	 * 再入室する。
	 */
	private function rejoin() {
		$this->session->lastNum = $this->messages->lastNum();
		$this->headers();
		$this->template->assign('sessionID', $this->session->id);
		return $this->template->output(SPEAK_TEMPLATE);
	}

	/**
	 * 発言/更新する。
	 */
	private function speak() {
		if ($_POST['mssg'] !== ''){
			$this->messages->add($_POST['mssg'],
				$this->session->name, FALSE, $this->session->color);

			//自動応答メッセージ
			$this->auto_response($_POST['mssg'], $this->session->name); #吉野追加
		}

		if ($this->isAjaxRequest()) {
			return $this->ajaxResponse();
		} else {
			$lastNum = $this->messages->lastNum();
			if ($this->session->lastNum < $lastNum) {
				$this->session->lastNum = $lastNum;
				$this->headers();
				$this->template->assign('sessionID', $this->session->id);
				return $this->template->output(SPEAK_TEMPLATE);
			} else {
				$this->headers(array('status' => '204 No Content'));
				return '';
			}
		}
	}

	/**
	 * 応答文の作成（吉野追加）
	 */
	private function auto_response($theWords, $userName) { //$theWordsにはユーザの文，$userNameにはユーザ名が入っている

		$chatBotName = '映画ソムリエ'; //チャットボットの名前
		$responseSentence = ''; //ここに入れた文字が返る

		//strposは、該当する文字列が見つからなかった場合は、falseを返す

		/****基本会話****/

		if( false !== strpos( $theWords, 'ありがとう') ){
			//$theWordsが同じ場合
			$responseSentence = 'どういたしまして！ ';
		}

		if( false !== strpos( $theWords, '助け') ){
			//$theWordsが同じ場合
			$responseSentence = $userName . 'さん、やっぱり、自助だよね！自分で頑張ろう！ ';
		}
		if( false !== strpos( $theWords, 'ふざけないで') ){
			//$theWordsが同じ場合
			$responseSentence = $userName . 'ふざけていませんよ〜〜〜〜にこっ♪ ';
		}

		if( false !== strpos( $theWords,  'こんにちは') ){
			//$theWordsが同じ場合
			$responseSentence = $userName . 'さん、こんにちは〜';
		}
		if( false !== strpos( $theWords, 'おはよう') ){
			//$theWordsが同じ場合
			$responseSentence = $userName . 'さん、おっはよ〜？';
		}
		if( false !== strpos($theWords,  'こんばんは') ){
			//$theWordsが同じ場合
			$responseSentence = $userName . 'さん、こんばんは？まだ早くない？';
		}
		if( false !== strpos($theWords,  '元気ですか') ){
			$responseSentence = 'わたしは、元気ですよ〜';
		}

		if ( false !== strpos( $theWords, '相談が' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$responseSentence =  $userName . 'さん、相談！どうぞ！聞いてあげる';
		}


		/**映画すすめる**/

		/////////////////////////////////////映画//////////////////////////////////

		if ( false !== strpos( $theWords, '映画' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$responseSentence =  $userName . 'さん，あなたにおすすめの映画ジャンルは，';
			$i = mt_rand(1,6);
			switch ($i) {
			    case 1:
					$responseSentence = $responseSentence . '『ホラー』';
			        break;
			    case 2:
					$responseSentence = $responseSentence . '『癒し』';
			        break;
				case 3:
					$responseSentence = $responseSentence . '『恋愛』';
					break;
				case 4:
					$responseSentence = $responseSentence . '『人生』';
					break;
				case 5:
					$responseSentence = $responseSentence . '『アクション』';
					break;
				case 6:
					$responseSentence = $responseSentence . '『コメディー』';
					break;

			}
			$responseSentence = $responseSentence . '系です。気に入ってくれたかな？';
		}

		//////////////////////////////////////////////////////////////////////

		if ( false !== strpos( $theWords, '人生' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$i = mt_rand(1,10);
			switch ($i) {
			    case 1:
					$responseSentence = '自分自身を信じてみるだけでいい。きっと、生きる道が見えてくる。って、ゲーテがいってたよ。';
			        break;
			    case 2:
					$responseSentence = 'たいていの人はほんとうになにがほしいのか、心の中でわかっています。人生の目標を教えてくれるのは直感だけ。';
			        break;
			    case 3:
					$responseSentence = $userName .  'さん、運がいい人も、運が悪い人もいない。運がいいと思う人と、運が悪いと思う人がいるだけだよ。';
			        break;
			    case 4:
					$responseSentence = $userName . 'さん、夢中で日を過ごしておれば、いつかはわかる時がくるって。';
			        break;
			    case 5:
					$responseSentence = $userName . 'さん、私たちの生きているこの世で起きることには、すべて原因があります。';
			        break;
			    case 6:
					$responseSentence =$userName . 'さん、人生は芝居のごとしよ。とかく、あまり人生を重く見ず、捨て身になって何事も一心になすべしって。';
			        break;
			    case 7:
					$responseSentence = $userName . 'さん、人生に失敗がないと、人生を失敗するっていわれているよ。';
			        break;
			    case 8:
					$responseSentence = $userName . 'さん、人生という試合で最も重要なのは、休憩時間の得点であるって，ナポレオンが言ってるよ。';
			        break;
			    case 9:
					$responseSentence = $userName . 'さん、人生は、むつかしく解釈するから、分からなくなるんだよ。';
			        break;
			    case 10:
					$responseSentence = $userName . 'さん、迷う、ということは、一種の欲望からきているように思う。';
			        break;
			}

			$j = mt_rand(1,4);
			switch ($j) {
			    case 1:
					$responseSentence = $responseSentence . '『ショーシャンクの空に』なんてどうかな？';
			        break;
			    case 2:
					$responseSentence = $responseSentence . '『イエスマン “YES”は人生のパスワード』なんてどうかな？';
			        break;
				case 3:
					$responseSentence = $responseSentence . '『きっと，うまくいく』なんてどうかな？';
					break;
				case 4:
					$responseSentence = $responseSentence . '『ターミナル』なんてどうかな？';
					break;
			}

		}

		if ( ( false !== strpos( $theWords, '疲れ' ) ) || ( false !== strpos( $theWords, '怠い' ) ) || ( false !== strpos( $theWords, 'つかれ' )) || ( false !== strpos( $theWords, '癒し' )) ){
    		// $theWordsに特定の文字が含まれる場合
			$responseSentence =  $userName . 'さん、疲れているの？ゆっくり休んだ方がいいよ〜 映画でも見て疲れを取ろう！';
			$i = mt_rand(1,4);
			switch ($i) {
			    case 1:
					$responseSentence = $responseSentence . '『となりのトトロ』なんてどうかな？';
			        break;
			    case 2:
					$responseSentence = $responseSentence . '『ホノカアボーイ』なんてどうかな？';
			        break;
				case 3:
					$responseSentence = $responseSentence . '『しあわせのパン』なんてどうかな？';
					break;
				case 4:
					$responseSentence = $responseSentence . '『サマーウォーズ』なんてどうかな？';
					break;
			}
		}

		if ( ( false !== strpos( $theWords, '悩' ) ) || ( false !== strpos( $theWords, 'コメディー' ) ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$responseSentence =  $userName . 'さん、面白い映画でもどう？';
			$i = mt_rand(1,6);
			switch ($i) {
			    case 1:
					$responseSentence = $responseSentence . '『ホーム・アローン』なんてどうかな？';
			        break;
			    case 2:
					$responseSentence = $responseSentence . '『天使にラブ・ソングを・・・』なんてどうかな？';
			        break;
			    case 3:
					$responseSentence = $responseSentence . '『飛んで埼玉』なんてどうかな？';
			        break;
				case 4:
					$responseSentence = $responseSentence . '『テルマエ・ロマエ』なんてどうかな？';
					break;
				case 5:
					$responseSentence = $responseSentence . '『マスク』なんてどうかな？';
					break;
				case 6:
					$responseSentence = $responseSentence . '『テッド』なんてどうかな？';
					break;
			}
		}

		if ( false !== strpos( $theWords, '恋' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$i = mt_rand(1,3);
			switch ($i) {
			    case 1:
					$responseSentence =  $userName . 'さん、恋は、もう全力で！後悔しないように！';
			        break;
			    case 2:
					$responseSentence =  $userName . 'さん、何かあったなら話してよ、なんでも聞くよ？';
			        break;
				case 3:
					$responseSentence =  $userName . 'さん、映画でもみて恋のことは考えるのやめちゃおう！';
					break;
			}

			$j = mt_rand(1,6); // １から3 までのランダムで返す
			switch ($j) {
			    case 1:
					$responseSentence = $responseSentence . '『君の膵臓をたべたい』なんてどうかな？';
			        break;
			    case 2:
					$responseSentence = $responseSentence . '『タイタニック』なんてどうかな？';
			        break;
			    case 3:
					$responseSentence = $responseSentence . '『アバウト・タイム～愛おしい時間について～』なんてどうかな？';
			        break;
				case 4:
					$responseSentence = $responseSentence . '『ラ・ラ・ランド』なんてどうかな？';
					break;
				case 5:
					$responseSentence = $responseSentence . '『ローマの休日』なんてどうかな？';
					break;
				case 6:
					$responseSentence = $responseSentence . '『ロミオとジュリエット』なんてどうかな？';
					break;
			}
		}

		if ( false !== strpos( $theWords, 'でも' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$responseSentence =  $userName . 'さん、でもとか言っていないで！もう全力で！';
		}

		if ( ( false !== strpos( $theWords, 'グー' )) || (false !== strpos( $theWords, 'チョキ' )) || (false !== strpos( $theWords, 'パー' ))) {
			if ( false !== strpos( $theWords, 'グー' )){
							$responseSentence =  $userName . 'さんは，グーで，私は，パーなので，私の勝ち！';
			}
			if ( false !== strpos( $theWords, 'チョキ' )){
							$responseSentence = $userName . 'さんはチョキで，私は，グーなので，私の勝ち！';
			}
			if ( false !== strpos( $theWords, 'パー' )){
							$responseSentence = $userName . 'さんはパーで，私は，チョキなので，私の勝ち！';
			}
		}

		if ( false !== strpos( $theWords, 'おみくじ' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$i = mt_rand(1,3); // １から3 までのランダムで返す
			switch ($i) {
			    case 1:
					$responseSentence = '「大吉」です。すごいね！';
			        break;
			    case 2:
					$responseSentence = '「吉」です。すごいね！';
			        break;
			    case 3:
					$responseSentence = '「凶」です。ひどいね！';
			        break;
			}
		}

		if ( false !== strpos( $theWords, 'おみくじしたい' ) ) {
			$responseSentence = ' おみくじしますか？ おみくじを引くって書くと，おみくじが引けるよ！';
		}

		if ( false !== strpos( $theWords, '天気' ) ) {
			$responseSentence = 'Yahoo!の天気予報で確認してください！ <https://weather.yahoo.co.jp/weather/> ';
		}


		if ( false !== strpos( $theWords, 'ホラー' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$responseSentence =  $userName . 'さん，ホラー映画が見たい気分ですか！？';
			$i = mt_rand(1,13);
			switch ($i) {
			    case 1:
					$responseSentence = $responseSentence . '『IT／イット “それ”が見えたら、終わり。』';
			        break;
			    case 2:
					$responseSentence = $responseSentence . '『悪魔のいけにえ』';
			        break;
				case 3:
					$responseSentence = $responseSentence . '『エクソシスト』';
					break;
				case 4:
					$responseSentence = $responseSentence . '『タエルム街の悪夢』';
					break;
				case 5:
					$responseSentence = $responseSentence . '『オーメン』';
					break;
				case 6:
					$responseSentence = $responseSentence . '『サイコ』';
					break;
				case 7:
					$responseSentence = $responseSentence . '『サスペリア』';
					break;
				case 8:
					$responseSentence = $responseSentence . '『キャリー』';
					break;
				case 9:
					$responseSentence = $responseSentence . '『ザ・リング』';
					break;
				case 10:
					$responseSentence = $responseSentence . '『バイオハザード』';
					break;
				case 11:
					$responseSentence = $responseSentence . '『ミスト』';
					break;
				case 12:
					$responseSentence = $responseSentence . '『呪怨』';
					break;
				case 13:
					$responseSentence = $responseSentence . '『エイリアン』';
					break;
			}
			$responseSentence = $responseSentence . 'なんてどうですか？';
		}

		if ( false !== strpos( $theWords, 'アクション' ) ) {
    		// $theWordsに特定の文字が含まれる場合
			$responseSentence =  $userName . 'さん，アクション映画が見たい気分ですか！？';
			$i = mt_rand(1,8);
			switch ($i) {
			    case 1:
					$responseSentence = $responseSentence . '『マトリックス』';
			        break;
			    case 2:
					$responseSentence = $responseSentence . '『アベンジャーズ』';
			        break;
				case 3:
					$responseSentence = $responseSentence . '『トランスフォーマー』';
					break;
				case 4:
					$responseSentence = $responseSentence . '『エイリアンVS.プレデター』';
					break;
				case 5:
					$responseSentence = $responseSentence . '『ワイルド・スピード』';
					break;
				case 6:
					$responseSentence = $responseSentence . '『ミッション：インポッシブル』';
					break;
				case 7:
					$responseSentence = $responseSentence . '『ダイ・ハード』';
					break;
				case 8:
					$responseSentence = $responseSentence . '『ターミネーター』';
					break;
			}
			$responseSentence = $responseSentence . 'なんてどうですか？';
		}



		//返す文字がなにもないときには，ランダムで
		if ($responseSentence == ''){
			if(mt_rand(1,20)>=20){	//１から20の数字をだす．>=20なので，20回に1回
				$responseSentence = 'おみくじを引くって書くと，おみくじが引けるよ？';
			}

			if(mt_rand(1,20)>=20){	//１から20の数字をだす．>=20なので，20回に1回
				$responseSentence = 'じゃんけんしよう？「グー」「チョキ」「パー」のどれかを出して？';
			}
		}

		//返す文字がなにもないときには，ランダムでなにかいう
		if ($responseSentence == ''){
			$i = mt_rand(1,10); // １から10までのランダムで返す
			switch ($i) {
			    case 1:
					$responseSentence = 'そうなんだ';
			        break;
			    case 2:
					$responseSentence = 'へぇ';
			        break;
			    case 3:
					$responseSentence = $userName .  'さん、続けて';
			        break;
			    case 4:
					$responseSentence = $userName . 'さん、本当？';
			        break;
			    case 5:
					$responseSentence = $userName . 'さん、それで？';
			        break;
			    case 6:
					$responseSentence =$userName . 'さん、びっくりした';
			        break;
			    case 7:
					$responseSentence = $userName . 'さん、楽しい？';
			        break;
			    case 8:
					$responseSentence = $userName . 'さん、もういい？';
			        break;
			    case 9:
					$responseSentence = $userName . 'さん、話題変えよう？';
			        break;
			    case 10:
					$responseSentence = $userName . 'さんの話をもっと話をききたい';
			        break;
			}
			$responseSentence = $responseSentence . ' 「映画」って書くとおすすめの映画ジャンルを教えるよ？';
		}




		//ここで返る文字を設定する
		if ($responseSentence !== ''){
			$this->messages->add($responseSentence,
					$chatBotName, FALSE, $this->session->color);
		}
	}



	/**
	 * 退室する。
	 */
	private function quit() {
		if ($this->session) {
			$this->sessions->destroy($this->session->id);
			$message = $this->session->name . 'さんが退室しました。';
			$this->messages->add($message, INFORMER_NAME, TRUE, 'inherit');
			$cookie = $this->session->toArray(array('name', 'color'));
			setcookie(COOKIE_NAME, serialize($cookie), time() + COOKIE_EXPIRES);
			setcookie('sid', FALSE);
		}
		$this->headers();
		return $this->template->output(QUIT_TEMPLATE);
	}

	/**
	 * エラーを表示する。
	 *
	 * @param string $message エラーの内容
	 */
	private function error($message) {
		if ($this->isAjaxRequest()) {
			$this->headers(array('type' => 'text/javascript'));
			return	'alert("' . addcslashes($message, "\\\t\n\r\"") . '");' .
					'location.href = location.href;';
		} else {
			$this->headers();
			$this->template->assign('message', $message);
			return $this->template->output(ERROR_TEMPLATE);
		}
	}

	/**
	 * Ajaxリクエストかどうかを検査する。
	 *
	 * @return boolean AjaxリクエストならTRUE、さもなくばFALSE
	 */
	private function isAjaxRequest() {
		return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
			$_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
	}

	/**
	 * Ajaxレスポンスを返す。
	 */
	private function ajaxResponse() {
		$response = array('messages' => array(), 'sessions' => array());

		$properties = array('name', 'message', 'isInfo', 'color', 'date');
		foreach ($this->messages as $message) {
			if ($message->number <= $this->session->lastNum)
				break;
			$response['messages'][] = $message->toArray($properties);
		}

		if ($response['messages']) {
			$this->session->lastNum = $this->messages->lastNum();

			$properties = array('name', 'color');
			foreach ($this->sessions as $session)
				$response['sessions'][] = $session->toArray($properties);

			$this->headers(array('type' => 'application/json'));
			return json_encode($response);
		} else {
			$this->headers(array('status' => '204 No Content'));
			return '';
		}
	}

	/**
	 * HTTPレスポンスヘッダを出力する。
	 */
	private function headers($options = array()) {
		$options += array('status' => '200 OK', 'type' => 'text/html');

		header("$_SERVER[SERVER_PROTOCOL] $options[status]");
		if (strpos($options['status'], '204') === 0) {
			header('Content-Length: 0');	// Proxomitron対策
			return;
		}
		header('Content-Type: ' . $options['type'] . '; charset=UTF-8');
		if ($options['type'] === 'text/html') {
			header('Content-Script-Type: text/javascript');
			header('Content-Style-Type: text/css');
		}
	}
}

/******************************************************************************/

/** echo(htmlspecialchars())へのショートカット。 */
function o($string) { echo htmlspecialchars($string); }

/******************************************************************************/

/*
 * メインルーチン
 */

set_error_handler(create_function('$no, $str, $file, $line',
	'throw new ErrorException($str, 0, $no, $file, $line);'));

if (get_magic_quotes_gpc()) {
	$_POST   = array_map('stripslashes', $_POST);
	$_COOKIE = array_map('stripslashes', $_COOKIE);
}

$application = new ChatApplication();
$application->run();
unset($application);
