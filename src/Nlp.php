<?php
declare(strict_types=1);

namespace RepairSync;

/**
 * Lightweight NLP used by the RAG retriever: normalisation, concept tagging, tokenising.
 * Understands plain-language English plus common Filipino/Taglish/Cebuano phrasing.
 * (Direct port of server/nlp.js so both versions rank identically.)
 */
final class Nlp
{
    private const STOP_TEXT = 'a an the and or but of to in on at for with without is are was were be been it its this that these those i my me we our you your he she they them his her their am do does did done doing have has had having not no so very really just also as by from up out into over then than too can could will would should may might must when while after before again still always sometimes often keeps keep got get gets getting there here what ko ang ng sa na naman lang po ba mo ito yan yun kay si sila kami ako ikaw mga ni';

    /** Concept lexicon: canonical concept => phrases (matched against normalised text). */
    public const CONCEPTS = [
    'c_no_power' => ['wont turn on', 'will not turn on', 'not turning on', 'doesnt turn on', 'does not turn on', 'wont power on', 'no power', 'dead', 'completely dead', 'wont start', 'wont boot', 'no signs of life', 'ayaw mag on', 'ayaw mag open', 'ayaw mo on', 'ayaw mu on', 'dili mo open', 'dili mo on', 'ayaw mag-on', 'wont switch on', 'nothing happens when i press power', 'black and wont'],
    'c_charging' => ['not charging', 'wont charge', 'doesnt charge', 'does not charge', 'charging problem', 'charge slowly', 'slow charging', 'charging slow', 'ayaw mag charge', 'dili mo charge', 'charger', 'charging port', 'charge port', 'loose charger', 'cable wiggle', 'only charges at certain angle', 'plug in'],
    'c_port' => ['charging port', 'usb port', 'port loose', 'lightning port', 'type c', 'usb c', 'loose port', 'port dirty', 'lint', 'cable wiggle', 'angle', 'jack'],
    'c_screen_crack' => ['cracked', 'crack', 'shattered', 'broken screen', 'broken display', 'spider web', 'basag', 'nabuak', 'lines on screen', 'dropped', 'fell', 'nahulog', 'nalaglag', 'glass broke', 'screen broke'],
    'c_display_fault' => ['black spots', 'ink', 'dead pixels', 'vertical lines', 'horizontal lines', 'green line', 'pink line', 'purple line', 'flicker', 'flickering', 'screen flashes', 'discolored', 'burn in', 'white spots', 'display bleeding', 'lcd'],
    'c_black_screen' => ['black screen', 'blank screen', 'no display', 'screen stays black', 'screen black', 'screen went black', 'nothing on screen', 'dark screen', 'screen is dark', 'walay display', 'ayaw mag display', 'no image'],
    'c_backlight' => ['backlight', 'very dim', 'barely visible', 'faint image', 'visible with flashlight', 'visible under flashlight', 'dim screen', 'dim display', 'flashlight'],
    'c_touch' => ['touch not working', 'touch doesnt work', 'unresponsive', 'not responding to touch', 'ghost touch', 'touches by itself', 'phantom touch', 'touch lag', 'dead zone', 'part of the screen', 'ayaw mo touch', 'touchscreen', 'touch screen', 'digitizer', 'self typing', 'types by itself'],
    'c_water' => ['water', 'liquid', 'wet', 'spill', 'spilled', 'coffee', 'rain', 'soaked', 'submerged', 'dropped in', 'humid', 'moisture', 'nabasa', 'nalubog', 'natapunan', 'natapon', 'nasagpa', 'basa', 'corrosion', 'drink', 'tubig', 'juice', 'soda'],
    'c_overheat' => ['overheat', 'overheating', 'hot', 'very hot', 'too hot', 'burning', 'heats up', 'heating', 'warm', 'init', 'mainit', 'thermal', 'shuts down when hot', 'throttle', 'fan loud', 'temperature'],
    'c_battery' => ['battery', 'drains fast', 'drain', 'draining', 'dies quickly', 'battery life', 'battery health', 'percentage drops', 'suddenly shuts off', 'shuts off at', 'turns off at', 'power off at', 'unexpected shutdown', 'swollen', 'bloated', 'bulging', 'expanded', 'lumi', 'nag-iinit ang battery', 'lowbat', 'low bat', 'nauubos', 'mabilis maubos', 'dili molungtad', 'maubos agad'],
    'c_swollen' => ['swollen', 'bloated', 'bulging', 'puffed', 'expanded', 'lifting screen', 'back cover lifting', 'trackpad raised', 'case bulging', 'screen lifting', 'namamaga', 'namaga', 'nagbukol', 'bumubukol', 'nagbubukol', 'umbok', 'umuumbok', 'bukol'],
    'c_boot_loop' => ['boot loop', 'bootloop', 'stuck on logo', 'stuck at logo', 'logo', 'restarts again', 'keeps restarting', 'restart loop', 'reboot', 'rebooting', 'stuck on apple', 'stuck on boot', 'recovery mode', 'fastboot', 'update failed', 'after update', 'stuck on startup', 'startup repair', 'no operating system', 'operating system not found', 'no bootable', 'cant boot into', 'blue screen', 'bsod', 'blue screens'],
    'c_slow' => ['slow', 'lagging', 'lag', 'laggy', 'freezing', 'freeze', 'hang', 'hangs', 'hanging', 'takes long', 'takes forever', 'sluggish', 'performance', 'mabagal', 'nag-hang', 'nag hang', 'maghang', 'nagka-lag', 'virus', 'malware', 'pop ups', 'popups', 'ads'],
    'c_storage' => ['storage full', 'storage is full', 'no space', 'out of space', 'not enough storage', 'memory full', 'cant install', 'cannot install', 'cant take photos', 'storage'],
    'c_speaker' => ['no sound', 'speaker', 'cant hear', 'cannot hear', 'muffled', 'crackling', 'distorted sound', 'earpiece', 'low volume', 'sound not working', 'walay tunog', 'walang tunog', 'audio'],
    'c_mic' => ['microphone', 'mic', 'cant hear me', 'cannot hear me', 'voice not heard', 'caller cant hear', 'people cant hear me', 'other person cant hear', 'dili madungog', 'hindi marinig', 'voice message', 'during calls'],
    'c_camera' => ['camera', 'blurry photos', 'blurry', 'camera black', 'camera wont open', 'camera error', 'lens', 'autofocus', 'focus', 'photo', 'photos', 'selfie', 'front camera', 'back camera', 'rear camera', 'camera shaking', 'vibrating'],
    'c_wifi_bt' => ['wifi', 'wi-fi', 'wireless', 'bluetooth', 'cant connect', 'wont connect', 'disconnects', 'keeps disconnecting', 'no networks', 'network not found', 'weak signal', 'airpods', 'no wifi', 'wi fi'],
    'c_cellular' => ['no signal', 'no service', 'sim', 'sim card', 'network signal', 'searching', 'cellular', 'mobile data', 'no network', 'invalid sim', 'no sim', 'emergency calls only', 'baseband', 'imei', 'walay signal', 'walang signal'],
    'c_button' => ['power button', 'volume button', 'button stuck', 'button not working', 'button doesnt click', 'button loose', 'side button', 'home button', 'buttons', 'press', 'ayaw mag-click', 'volume up', 'volume down'],
    'c_fingerprint' => ['fingerprint', 'face id', 'touch id', 'face unlock', 'biometric', 'sensor'],
    'c_keyboard' => ['keyboard', 'keys', 'key stuck', 'key not working', 'keys not working', 'some keys', 'sticky keys', 'typing wrong', 'types wrong', 'letters', 'backlit', 'keycap', 'key'],
    'c_trackpad' => ['trackpad', 'touchpad', 'cursor', 'mouse pad', 'click not working', 'cant click', 'clicking', 'touch pad'],
    'c_fan' => ['fan', 'loud fan', 'fan noise', 'fan loud', 'fan not spinning', 'fan error', 'noisy', 'whirring', 'buzzing', 'grinding noise', 'dust', 'dusty', 'thermal paste', 'vents'],
    'c_hdd' => ['hard drive', 'hard disk', 'hdd', 'ssd', 'disk', 'clicking noise', 'clicking sound', 'click of death', 'corrupt', 'corrupted', 'files missing', 'drive not detected', 'no disk', 'disk error', 'disk read error', 'smart error', 'taking long to boot', 'long boot', 'bad sectors', 'data lost', 'cant find drive', 'ssd not detected'],
    'c_ram' => ['ram', 'memory error', 'beeping', 'beeps', 'beep codes', 'random restarts', 'random crashes', 'memory', 'blue screen memory', 'crashes randomly', 'randomly restarts', 'randomly shuts', 'out of memory', 'ram slot'],
    'c_dc_jack' => ['dc jack', 'charger port', 'charging jack', 'adapter', 'power adapter', 'not detecting charger', 'charger not detected', 'not plugged in', 'plugged in not charging', 'charger light', 'barrel', 'power connector', 'ac adapter', 'laptop charger'],
    'c_hinge' => ['hinge', 'hinges', 'cover loose', 'lid loose', 'lid wont stay', 'wont stay open', 'broken hinge', 'screen wobbles', 'lid cracked', 'cant close', 'wobbly', 'plastic cracked near hinge', 'creaking'],
    'c_lcd_cable' => ['flickers when opening', 'flicker when moving lid', 'flickers when moving the lid', 'moving the lid', 'moving the screen', 'screen flickers when', 'lid angle', 'lid position', 'cable', 'flex cable', 'only works at certain angle', 'external monitor works', 'works on external', 'works on external monitor', 'hdmi works'],
    'c_no_post' => ['no post', 'fans spin but no display', 'fans spin no display', 'lights on but no display', 'power light on', 'led on but', 'powers on but nothing', 'turns on but black', 'turns on then off', 'turns on but no display', 'turns on but screen black', 'starts then stops', 'turns on and off'],
    'c_short' => ['short circuit', 'burning smell', 'smoke', 'sparks', 'burnt smell', 'smells burnt', 'power surge', 'brownout', 'thunderstorm', 'lightning', 'brownout', 'overvoltage', 'amoy sunog', 'nasunog', 'smell like burning'],
    'c_dropped' => ['dropped', 'fell', 'impact', 'hit', 'cracked corner', 'bent', 'bend', 'dent', 'dented', 'nahulog', 'nalaglag', 'bumped', 'stepped on', 'natapakan', 'sat on', 'naupuan'],
    'c_vibration' => ['vibration', 'vibrate', 'vibrator', 'haptic', 'wont vibrate', 'no vibration'],
    'c_sim_tray' => ['sim tray', 'sim not detected', 'sim slot', 'sd card'],
    'c_headphone' => ['headphone jack', 'headset', 'earphone', 'earphones', 'aux', 'headphone', '3.5mm'],
    'c_auto_off' => ['shuts down', 'shuts off', 'turns off by itself', 'turns off randomly', 'automatically shuts', 'suddenly turns off', 'suddenly dies', 'dies suddenly', 'power cuts', 'naga-off', 'nag-off', 'namatay', 'bigla namatay', 'nag shutdown', 'shutdown by itself'],
    ];

    private static ?array $stop = null;
    private static ?array $entries = null;

    private static function stopWords(): array
    {
        return self::$stop ??= array_flip(explode(' ', self::STOP_TEXT));
    }

    /** Lowercase, strip accents/punctuation, collapse contractions. */
    public static function normalize(?string $text = ''): string
    {
        $t = mb_strtolower((string)$text, 'UTF-8');
        if (class_exists('Normalizer')) {
            $t = \Normalizer::normalize($t, \Normalizer::FORM_KD) ?: $t;
        } else {
            $t = strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c']);
        }
        $t = preg_replace('/[\x{0300}-\x{036F}]/u', '', $t);
        $t = preg_replace('/[\x{2019}\x{2018}`]/u', "'", $t);
        $t = str_replace(["won't", "can't", "doesn't", "don't", "isn't", "didn't", "couldn't"], ['wont', 'cant', 'doesnt', 'dont', 'isnt', 'didnt', 'couldnt'], $t);
        $t = preg_replace("/'s\\b/", '', $t);
        $t = str_replace("'", '', $t);
        $t = preg_replace('/[^a-z0-9\s\.]/', ' ', $t);
        $t = preg_replace('/(\d)\.(\d)/', '$1_$2', $t);
        $t = str_replace('.', ' ', $t);
        $t = preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }

    /** Light suffix stemmer (deterministic, dependency free). */
    public static function stem(string $w): string
    {
        if (strlen($w) <= 3) return $w;
        $w = preg_replace('/(ies)$/', 'y', $w);
        $w = preg_replace_callback('/(ing|edly|ed|ly|es|s)$/', fn($m) => $m[0][1] >= 3 ? '' : $m[0][0], $w, -1, $c, PREG_OFFSET_CAPTURE);
        return preg_replace('/(.)\1$/', '$1', $w);
    }

    private static function entries(): array
    {
        if (self::$entries === null) {
            self::$entries = [];
            foreach (self::CONCEPTS as $c => $phrases) {
                $norm = [];
                foreach ($phrases as $p) {
                    $n = self::normalize($p);
                    if ($n !== '') $norm[] = $n;
                }
                self::$entries[] = [$c, $norm];
            }
        }
        return self::$entries;
    }

    /** Concepts triggered by the text (whole-word / phrase match). */
    public static function detectConcepts(string $text): array
    {
        $t = ' ' . self::normalize($text) . ' ';
        $hit = [];
        foreach (self::entries() as [$concept, $phrases]) {
            foreach ($phrases as $p) {
                if (str_contains($t, " $p ")) { $hit[] = $concept; break; }
            }
        }
        return $hit;
    }

    /** Weighted token list: word stems plus up-weighted concept tokens. */
    public static function tokenize(string $text, int $conceptWeight = 3): array
    {
        $stop = self::stopWords();
        $tokens = [];
        foreach (explode(' ', self::normalize($text)) as $w) {
            if ($w === '' || strlen($w) <= 1 || isset($stop[$w])) continue;
            $tokens[] = self::stem($w);
        }
        foreach (self::detectConcepts($text) as $c) {
            for ($i = 0; $i < $conceptWeight; $i++) $tokens[] = $c;
        }
        return $tokens;
    }
}
