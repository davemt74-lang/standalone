<?php
declare(strict_types=1);

/**
 * Minimal UTF-8 compatibility layer for hosts where ext-mbstring is unavailable.
 *
 * Annotated prefers mbstring when present. These guarded fallbacks keep the
 * application operational on common shared-hosting PHP builds that provide
 * neither mbstring nor a way for the application to enable it at runtime.
 *
 * The case-conversion fallbacks use PHP's byte-oriented strtolower/strtoupper,
 * so full Unicode case folding still requires ext-mbstring. Length/substr use
 * iconv when available and otherwise fall back to core string functions.
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding=null): int {
        $encoding=$encoding?:'UTF-8';
        if(function_exists('iconv_strlen')){
            $length=@iconv_strlen($string,$encoding);
            if($length!==false)return $length;
        }
        if($encoding==='UTF-8'||strcasecmp($encoding,'UTF-8')===0){
            $matched=preg_match_all('/./us',$string,$unused);
            if($matched!==false)return $matched;
        }
        return strlen($string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length=null, ?string $encoding=null): string {
        $encoding=$encoding?:'UTF-8';
        if(function_exists('iconv_substr')){
            $effectiveLength=$length;
            if($effectiveLength===null){
                $total=function_exists('iconv_strlen')?@iconv_strlen($string,$encoding):false;
                if($total!==false)$effectiveLength=max(0,$total);
            }
            if($effectiveLength!==null){
                $result=@iconv_substr($string,$start,$effectiveLength,$encoding);
                if($result!==false)return $result;
            }
        }
        if(($encoding==='UTF-8'||strcasecmp($encoding,'UTF-8')===0)&&preg_match_all('/./us',$string,$matches)!==false){
            $chars=$matches[0];
            $count=count($chars);
            $offset=$start<0?max(0,$count+$start):min($count,$start);
            if($length===null)return implode('',array_slice($chars,$offset));
            $take=$length<0?max(0,$count-$offset+$length):$length;
            return implode('',array_slice($chars,$offset,$take));
        }
        return $length===null?substr($string,$start):substr($string,$start,$length);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding=null): string {
        return strtolower($string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding=null): string {
        return strtoupper($string);
    }
}

if (!function_exists('mb_stripos')) {
    function mb_stripos(string $haystack, string $needle, int $offset=0, ?string $encoding=null): int|false {
        $encoding=$encoding?:'UTF-8';
        if($needle==='')return 0;
        if(($encoding==='UTF-8'||strcasecmp($encoding,'UTF-8')===0)&&preg_match('/'.preg_quote($needle,'/').'/iu',mb_substr($haystack,$offset),$match,PREG_OFFSET_CAPTURE)){
            $byteOffset=(int)$match[0][1];
            $prefix=substr(mb_substr($haystack,$offset),0,$byteOffset);
            return $offset+mb_strlen($prefix,$encoding);
        }
        $position=stripos($haystack,$needle,$offset);
        return $position===false?false:$position;
    }
}
