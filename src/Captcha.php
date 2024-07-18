<?php

declare(strict_types=1);

namespace dongo\Captcha;

use support\Redis;

class Captcha
{


    /**
     * 验证验证码是否正确
     * @param string $code
     * @param string $key
     * @return bool
     */
    public static function check(string $code, string $key)
    {
        $config = config('plugin.dongo.captcha.app.captcha');
        $cacheKey = $config['prefix'] . $key;
        if (!Redis::exists($cacheKey)) {
            return false;
        }

        $hash = Redis::hGet($cacheKey, 'key');
        $code = mb_strtolower($code, 'UTF-8');
        $res = password_verify($code, $hash);
        if ($res) {
            Redis::del($cacheKey);
        }

        return $res;
    }



    /**
     * 输出验证码，并把验证码保存到缓存中
     * @param array $_config
     * @return array
     * @throws \Exception
     */
    public static function base64(array $_config = [])
    {
        $config = config('plugin.dongo.captcha.app.captcha');
        if (!empty($_config)) {
            $config = array_merge($config, $_config);
        }

        $generator = self::generate($config);

        # 图片宽(px)
        $config['imageW'] || $config['imageW'] = $config['length'] * $config['fontSize'] * 1.5 + $config['length'] * $config['fontSize'] / 2;

        # 图片高(px)
        $config['imageH'] || $config['imageH'] = $config['fontSize'] * 2.5;

        # 建立一张 $config['imageW'] * $config['imageH'] 的图像
        $im = imagecreate((int)$config['imageW'], (int)$config['imageH']);

        # 设置图片背景
        imagecolorallocate($im, $config['bg'][0], $config['bg'][1], $config['bg'][2]);

        # 验证码字体随机颜色
        $color = imagecolorallocate($im, mt_rand(1, 150), mt_rand(1, 150), mt_rand(1, 150));

        // 验证码使用随机字体
        $ttfPath = __DIR__ . '/../assets/' . ($config['useZh'] ? 'zhttfs' : 'ttfs') . '/';

        if (empty($config['fontttf'])) {
            $dir = dir($ttfPath);
            $ttfs = [];
            while (false !== ($file = $dir->read())) {
                if (substr($file, -4) === '.ttf' || substr($file, -4) === '.otf') {
                    $ttfs[] = $file;
                }
            }
            $dir->close();
            $config['fontttf'] = $ttfs[array_rand($ttfs)];
        }

        $fontttf = $ttfPath . $config['fontttf'];

        # 添加背景图片
        if ($config['useImgBg']) {
            self::background($config, $im);
        }

        # 绘制杂点
        if ($config['useNoise']) {
            self::writeNoise($config, $im);
        }

        # 绘制干扰线
        if ($config['useCurve']) {
            self::writeCurve($config, $im, $color);
        }

        // 绘制验证码
        $text = $config['useZh'] ? preg_split('/(?<!^)(?!$)/u', $generator['value']) : preg_split($generator['value']);

        foreach ($text as $index => $char) {
            $x = $config['fontSize'] * ($index + 1) * mt_rand((int)1.2, (int)1.6) * ($config['math'] ? 1 : 1.5);
            $y = $config['fontSize'] + mt_rand(10, 20);
            $angle = $config['math'] ? 0 : mt_rand(-40, 40);
            imagettftext($im, $config['fontSize'], $angle, (int) $x, (int) $y, $color, $fontttf, $char);
        }

        ob_start();
        imagepng($im);
        $content = ob_get_clean();
        imagedestroy($im);

        return [
            'key' => $generator['key'],
            'base64' => 'data:image/png;base64,' . base64_encode($content)
        ];
    }


    /**
     * 创建验证码
     * @param array $config
     * @return array
     * @throws \Exception
     */
    public static function generate(array $config): array
    {
        $bag = '';
        if ($config['math']) {
            $config['useZh'] = false;
            $config['length'] = 5;
            $x = random_int(10, 30);
            $y = random_int(1, 9);
            $bag = "{$x} + {$y} = ";
            $key = $x + $y;
            $key .= '';
        } else {
            if ($config['useZh']) {
                $characters = preg_split('/(?<!^)(?!$)/u', $config['zhSet']);
            } else {
                $characters = str_split($config['codeSet']);
            }

            for ($i = 0; $i < $config['length']; $i++) {
                $bag .= $characters[rand(0, count($characters) - 1)];
            }

            $key = mb_strtolower($bag, 'UTF-8');
        }

        $config = config('plugin.dong.captcha.app.captcha');
        $hash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]);
        Redis::multi();
        Redis::hMSet($config['prefix'] . $hash, ['key' => $hash]);
        Redis::expire($config['prefix'] . $hash, $config['expire'] ?? 60);
        Redis::exec();
        return ['value' => $bag, 'key' => $hash];
    }


    /**
     * @desc 绘制背景图片
     * @param array $config
     * @param $im
     * @return void
     */
    public static function background(array $config, $im): void
    {
        $path = __DIR__ . '/../assets/bgs/';

        $dir = dir($path);

        $bgs = [];

        while (false !== ($file = $dir->read())) {
            if ('.' !== $file && substr($file, -4) === '.jpg') {
                $bgs = $path . $file;
            }
        }
        $dir->close();

        $gb = $bgs[array_rand($bgs)];
        [$width, $height] = @getimagesize($gb);
        $bgImage = @imagecolorallocate($gb);
        @imagecopyresampled($im, $bgImage, 0, 0, 0, 0, (int)$config['imageW'], (int)$config['imageH'], $width, $height);
        @imagedestroy($bgImage);
    }


    /**
     * @desc 绘制杂点
     * @param array $config
     * @param $im
     * @return void
     */
    public static function writeNoise(array $config, $im)
    {
        $codeStr = 'abcdefghijklmnopqrstuvwxyz20240717dongo';
        for ($i = 0; $i < 10; $i++) {
            # 杂点颜色
            $noiseColor = imagecolorallocate($im, mt_rand(150, 225), mt_rand(150, 255), mt_rand(150, 255));

            for ($j = 0; $j < 5; $j++ ){
                # 绘杂点
                imagestring($im, 5, mt_rand(-10, (int)$config['imageW']), mt_rand(-10, (int)$config['iamgeH']), $codeStr[mt_rand(0, 29)], (int) $noiseColor);
            }
        }
    }

    /**
     * @desc 画干扰线
     * @param array $config
     * @param $im
     * @param $color
     * @return void
     */
    public static function writeCurve(array $config, $im, $color)
    {
        $py = 0;

        # 曲线前部分
        # 振幅
        $A = mt_rand(1, (int)($config['imageW'] / 2));
        # Y轴偏移量
        $b = mt_rand(-(int)($config['imageH'] / 4), (int)($config['imageH']));
        # X轴偏移量
        $f = mt_rand(-(int)($config['imageW'] / 4), (int)($config['imageW']));
        # 周期
        $T = mt_rand((int)$config['imageH'], (int)($config['imageW'] * 2));
        $w = (2 * M_PI) / $T;

        # 曲线横坐标起始位置
        $px1 = 0;
        # 曲线横坐标结束位置
        $px2 = mt_rand((int) ($config['imageW'] / 2), (int)$config['imageW']);

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if ( 0 !== $w) {
                # y = Asin(ωx+φ) + b
                $py = $A * sin($w * $px + $f) +$b + $config['imageH'] / 2;
                $i = (int) ($config['fontSize'] / 5);
                while ($i > 0) {
                    imagesetpixel($im, (int)$px + $i, (int)$py +$i, (int)$color);
                    $i--;
                }
            }
        }

        # 曲线后半部分
        $A   = mt_rand(1, (int) ($config['imageH'] / 2)); // 振幅
        $f   = mt_rand(-(int) ($config['imageH'] / 4), (int) ($config['imageH'] / 4)); // X轴方向偏移量
        $T   = mt_rand((int) $config['imageH'], (int) ($config['imageW'] * 2)); // 周期
        $w   = (2 * M_PI) / $T;
        $b   = $py - $A * sin($w * $px + $f) - $config['imageH'] / 2;
        $px1 = $px2;
        $px2 = $config['imageW'];

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $config['imageH'] / 2; // y = Asin(ωx+φ) + b
                $i  = (int) ($config['fontSize'] / 5);
                while ($i > 0) {
                    imagesetpixel($im, (int) $px + $i, (int) $py + $i, (int)$color);
                    $i--;
                }
            }
        }

    }
}