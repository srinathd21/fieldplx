<?php
/*
 * FieldPlx bundled FPDF-compatible fallback.
 * It implements the small FPDF surface used by the Business Profile preview.
 * If the project already contains the official setasign/fpdf package, the
 * preview endpoint loads that package first and this file is not used.
 */
if (class_exists('FPDF', false)) { return; }
if (!defined('FIELDPLX_FPDF_FALLBACK')) define('FIELDPLX_FPDF_FALLBACK', 1);
class FPDF
{
    protected $k = 2.834645669;
    protected $w = 210.0;
    protected $h = 297.0;
    protected $x = 10.0;
    protected $y = 10.0;
    protected $lMargin = 10.0;
    protected $rMargin = 10.0;
    protected $tMargin = 10.0;
    protected $bMargin = 10.0;
    protected $fontSizePt = 10.0;
    protected $fontStyle = '';
    protected $fontFamily = 'Helvetica';
    protected $drawColor = array(0,0,0);
    protected $fillColor = array(255,255,255);
    protected $textColor = array(0,0,0);
    protected $lineWidth = .2;
    protected $pages = array();
    protected $page = -1;
    protected $autoPageBreak = true;
    protected $title = '';
    protected $images = array();
    protected $imageSeq = 0;

    public function __construct($orientation='P',$unit='mm',$size='A4')
    {
        if (strtoupper((string)$orientation) === 'L') { $this->w = 297.0; $this->h = 210.0; }
    }
    public function SetTitle($title){ $this->title=(string)$title; }
    public function SetMargins($left,$top,$right=null){ $this->lMargin=(float)$left; $this->tMargin=(float)$top; $this->rMargin=$right===null?(float)$left:(float)$right; $this->x=$this->lMargin; $this->y=$this->tMargin; }
    public function SetAutoPageBreak($auto,$margin=0){ $this->autoPageBreak=(bool)$auto; $this->bMargin=(float)$margin; }
    public function AddPage($orientation='',$size='',$rotation=0){ $this->page++; $this->pages[$this->page]=''; $this->x=$this->lMargin; $this->y=$this->tMargin; }
    protected function ensurePage(){ if($this->page<0)$this->AddPage(); }
    protected function out($s){ $this->ensurePage(); $this->pages[$this->page].=$s."\n"; }
    protected function enc($s){ $s=(string)$s; if(function_exists('iconv')){ $v=@iconv('UTF-8','Windows-1252//TRANSLIT',$s); if($v!==false)$s=$v; } return str_replace(array('\\','(',')',"\r","\n"),array('\\\\','\\(','\\)',' ',' '),$s); }
    protected function rgb($c){ return sprintf('%.3F %.3F %.3F',$c[0]/255,$c[1]/255,$c[2]/255); }
    protected function fontKey(){ return strtoupper($this->fontStyle)==='B'?'F2':'F1'; }
    public function SetFont($family,$style='',$size=0){ $this->fontFamily=(string)$family; $this->fontStyle=(string)$style; if((float)$size>0)$this->fontSizePt=(float)$size; }
    public function SetFontSize($size){ $this->fontSizePt=(float)$size; }
    public function SetTextColor($r,$g=null,$b=null){ if($g===null){$g=$b=$r;} $this->textColor=array((int)$r,(int)$g,(int)$b); }
    public function SetDrawColor($r,$g=null,$b=null){ if($g===null){$g=$b=$r;} $this->drawColor=array((int)$r,(int)$g,(int)$b); }
    public function SetFillColor($r,$g=null,$b=null){ if($g===null){$g=$b=$r;} $this->fillColor=array((int)$r,(int)$g,(int)$b); }
    public function SetLineWidth($width){ $this->lineWidth=(float)$width; }
    public function GetX(){ return $this->x; }
    public function GetY(){ return $this->y; }
    public function SetX($x){ $this->x=(float)$x; }
    public function SetY($y,$resetX=true){ $this->y=(float)$y; if($resetX)$this->x=$this->lMargin; }
    public function SetXY($x,$y){ $this->x=(float)$x; $this->y=(float)$y; }
    public function Ln($h=null){ $this->x=$this->lMargin; $this->y += $h===null ? ($this->fontSizePt/$this->k*1.2) : (float)$h; }
    public function GetStringWidth($s){ return strlen($this->enc($s))*($this->fontSizePt*0.48)/$this->k; }
    protected function pageCheck($h){ if($this->autoPageBreak && $this->y+$h>$this->h-$this->bMargin){$this->AddPage();} }
    public function Line($x1,$y1,$x2,$y2){ $this->out(sprintf('%.3F w %s RG %.3F %.3F m %.3F %.3F l S',$this->lineWidth*$this->k,$this->rgb($this->drawColor),$x1*$this->k,($this->h-$y1)*$this->k,$x2*$this->k,($this->h-$y2)*$this->k)); }
    public function Rect($x,$y,$w,$h,$style=''){ $op='S'; if($style==='F')$op='f'; elseif($style==='FD'||$style==='DF')$op='B'; $this->out(sprintf('%.3F w %s RG %s rg %.3F %.3F %.3F %.3F re %s',$this->lineWidth*$this->k,$this->rgb($this->drawColor),$this->rgb($this->fillColor),$x*$this->k,($this->h-$y-$h)*$this->k,$w*$this->k,$h*$this->k,$op)); }
    public function Cell($w,$h=0,$txt='',$border=0,$ln=0,$align='',$fill=false,$link='')
    {
        $this->ensurePage(); $w=(float)$w; $h=(float)$h; if($w==0)$w=$this->w-$this->rMargin-$this->x; $this->pageCheck($h);
        if($fill || $border){ $style=$fill?($border?'FD':'F'):'D'; $this->Rect($this->x,$this->y,$w,$h,$style); }
        if($txt!==''){
            $tw=$this->GetStringWidth($txt); $tx=$this->x+1.2; if($align==='C')$tx=$this->x+($w-$tw)/2; elseif($align==='R')$tx=$this->x+$w-$tw-1.2;
            $baseline=$this->y+($h>0?($h*0.68):($this->fontSizePt/$this->k));
            $this->out(sprintf('BT /%s %.2F Tf %s rg 1 0 0 1 %.3F %.3F Tm (%s) Tj ET',$this->fontKey(),$this->fontSizePt,$this->rgb($this->textColor),$tx*$this->k,($this->h-$baseline)*$this->k,$this->enc($txt)));
        }
        $this->x += $w; if($ln>0){ $this->y += $h; $this->x=$this->lMargin; }
    }
    public function MultiCell($w,$h,$txt,$border=0,$align='J',$fill=false)
    {
        $w=(float)$w; $h=(float)$h; $usable=max(4,$w-2); $avg=max(.7,($this->fontSizePt*.48)/$this->k); $chars=max(1,(int)floor($usable/$avg));
        // Preserve the caller's starting X for every wrapped/newline row. The previous
        // fallback used Cell(..., ln=1) without restoring X, which shifted line 2+ back
        // to the document's left margin and caused address/contact text misalignment.
        $startX=$this->x;
        $paragraphs=preg_split('/\r?\n/',(string)$txt);
        foreach($paragraphs as $p){
            $wrapped=wordwrap($p,$chars,"\n",true);
            foreach(explode("\n",$wrapped) as $line){
                $this->x=$startX;
                $this->Cell($w,$h,$line,$border,1,$align,$fill);
            }
        }
        $this->x=$this->lMargin;
    }
    protected function paeth($a,$b,$c)
    {
        $p=$a+$b-$c; $pa=abs($p-$a); $pb=abs($p-$b); $pc=abs($p-$c);
        if($pa<=$pb && $pa<=$pc)return $a; if($pb<=$pc)return $b; return $c;
    }
    protected function pngUnfilter($data,$w,$h,$bpp,$rowBytes)
    {
        $rows=array(); $pos=0; $prev=array_fill(0,$rowBytes,0);
        for($y=0;$y<$h;$y++){
            if($pos>=strlen($data))return false;
            $filter=ord($data[$pos++]); $scan=array();
            for($i=0;$i<$rowBytes;$i++){$scan[$i]=$pos<strlen($data)?ord($data[$pos++]):0;}
            $out=array_fill(0,$rowBytes,0);
            for($i=0;$i<$rowBytes;$i++){
                $left=$i>=$bpp?$out[$i-$bpp]:0; $up=$prev[$i]; $upleft=$i>=$bpp?$prev[$i-$bpp]:0; $v=$scan[$i];
                if($filter===1)$v=($v+$left)&255;
                elseif($filter===2)$v=($v+$up)&255;
                elseif($filter===3)$v=($v+(int)floor(($left+$up)/2))&255;
                elseif($filter===4)$v=($v+$this->paeth($left,$up,$upleft))&255;
                elseif($filter!==0)return false;
                $out[$i]=$v;
            }
            $rows[]=$out; $prev=$out;
        }
        return $rows;
    }
    protected function parseJpeg($file)
    {
        $info=@getimagesize($file); if(!$info)return false;
        $channels=isset($info['channels'])?(int)$info['channels']:3;
        return array('w'=>(int)$info[0],'h'=>(int)$info[1],'cs'=>$channels===1?'DeviceGray':'DeviceRGB','bpc'=>8,'filter'=>'DCTDecode','data'=>(string)@file_get_contents($file),'smask'=>null);
    }
    protected function parsePng($file)
    {
        $fh=@fopen($file,'rb'); if(!$fh)return false;
        if(fread($fh,8)!="\x89PNG\r\n\x1a\n"){fclose($fh);return false;}
        $w=$h=$bpc=$ct=$interlace=0; $palette=''; $trns=''; $idat='';
        while(!feof($fh)){
            $lb=fread($fh,4); if(strlen($lb)<4)break; $len=unpack('N',$lb)[1]; $type=fread($fh,4); $chunk=$len?fread($fh,$len):''; fread($fh,4);
            if($type==='IHDR'){$v=unpack('Nw/Nh/Cbpc/Cct/Ccomp/Cfilter/Cinterlace',$chunk);$w=$v['w'];$h=$v['h'];$bpc=$v['bpc'];$ct=$v['ct'];$interlace=$v['interlace'];}
            elseif($type==='PLTE')$palette=$chunk; elseif($type==='tRNS')$trns=$chunk; elseif($type==='IDAT')$idat.=$chunk; elseif($type==='IEND')break;
        }
        fclose($fh);
        if($w<=0||$h<=0||$interlace!==0||!in_array($ct,array(0,2,3,4,6),true))return false;
        if(!in_array($bpc,array(8,16),true))return false;
        if($ct===3 && $bpc!==8)return false;
        $inflated=@gzuncompress($idat); if($inflated===false)return false;
        $channels=array(0=>1,2=>3,3=>1,4=>2,6=>4)[$ct];
        $bytesPerSample=$bpc===16?2:1; $bytesPerPixel=$channels*$bytesPerSample; $rowBytes=$w*$bytesPerPixel;
        $rows=$this->pngUnfilter($inflated,$w,$h,$bytesPerPixel,$rowBytes); if($rows===false)return false;
        $colorRaw=''; $alphaRaw=''; $hasAlpha=false; $colors=($ct===0||$ct===4)?1:3;
        $pal=array(); if($ct===3){for($i=0;$i+2<strlen($palette);$i+=3)$pal[]=array(ord($palette[$i]),ord($palette[$i+1]),ord($palette[$i+2]));}
        foreach($rows as $row){
            $colorRaw.="\x00"; $alphaLine='';
            if($ct===0){
                if($bpc===8){foreach($row as $g)$colorRaw.=chr($g);}else{for($i=0;$i<count($row);$i+=2)$colorRaw.=chr($row[$i]);}
            }elseif($ct===2){
                if($bpc===8){foreach($row as $v)$colorRaw.=chr($v);}else{for($i=0;$i<count($row);$i+=6)$colorRaw.=chr($row[$i]).chr($row[$i+2]).chr($row[$i+4]);}
            }elseif($ct===3){
                foreach($row as $idx){$rgb=isset($pal[$idx])?$pal[$idx]:array(0,0,0);$colorRaw.=chr($rgb[0]).chr($rgb[1]).chr($rgb[2]);$a=($trns!==''&&$idx<strlen($trns))?ord($trns[$idx]):255;$alphaLine.=chr($a);if($a<255)$hasAlpha=true;}
            }elseif($ct===4){
                if($bpc===8){for($i=0;$i<count($row);$i+=2){$colorRaw.=chr($row[$i]);$alphaLine.=chr($row[$i+1]);if($row[$i+1]<255)$hasAlpha=true;}}
                else{for($i=0;$i<count($row);$i+=4){$colorRaw.=chr($row[$i]);$a=$row[$i+2];$alphaLine.=chr($a);if($a<255)$hasAlpha=true;}}
            }elseif($ct===6){
                if($bpc===8){for($i=0;$i<count($row);$i+=4){$colorRaw.=chr($row[$i]).chr($row[$i+1]).chr($row[$i+2]);$alphaLine.=chr($row[$i+3]);if($row[$i+3]<255)$hasAlpha=true;}}
                else{for($i=0;$i<count($row);$i+=8){$colorRaw.=chr($row[$i]).chr($row[$i+2]).chr($row[$i+4]);$a=$row[$i+6];$alphaLine.=chr($a);if($a<255)$hasAlpha=true;}}
            }
            if($ct===3||$ct===4||$ct===6)$alphaRaw.="\x00".$alphaLine;
        }
        return array('w'=>$w,'h'=>$h,'cs'=>$colors===1?'DeviceGray':'DeviceRGB','bpc'=>8,'filter'=>'FlateDecode','data'=>gzcompress($colorRaw),'smask'=>$hasAlpha?gzcompress($alphaRaw):null,'colors'=>$colors);
    }
    public function Image($file,$x=null,$y=null,$w=0,$h=0,$type='',$link='')
    {
        $file=(string)$file; if(!is_file($file))return;
        $key=realpath($file)?:$file;
        if(!isset($this->images[$key])){
            $ext=strtolower($type!==''?$type:pathinfo($file,PATHINFO_EXTENSION)); if($ext==='jpeg')$ext='jpg';
            $info=$ext==='png'?$this->parsePng($file):($ext==='jpg'?$this->parseJpeg($file):false); if(!$info)return;
            $this->imageSeq++; $info['name']='I'.$this->imageSeq; $info['obj']=0; $info['smask_obj']=0; $this->images[$key]=$info;
        }
        $im=$this->images[$key]; $x=$x===null?$this->x:(float)$x; $y=$y===null?$this->y:(float)$y; $w=(float)$w; $h=(float)$h;
        if($w<=0&&$h<=0){$w=$im['w']*25.4/96; $h=$im['h']*25.4/96;}
        elseif($w<=0)$w=$h*$im['w']/$im['h']; elseif($h<=0)$h=$w*$im['h']/$im['w'];
        $this->out(sprintf('q %.3F 0 0 %.3F %.3F %.3F cm /%s Do Q',$w*$this->k,$h*$this->k,$x*$this->k,($this->h-$y-$h)*$this->k,$im['name']));
        if($y===null)$this->y+=$h;
    }
    protected function buildPdf()
    {
        if(!$this->pages)$this->AddPage();
        $objects=array(); $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
        $pageCount=count($this->pages); $kids=array(); $font1=3; $font2=4;
        $objects[$font1]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$font2]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next=5; $xobjects=array();
        foreach($this->images as $k=>$im){
            $smaskObj=0; if(!empty($im['smask'])){$smaskObj=$next++; $colors=1; $dp='<< /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns '.(int)$im['w'].' >>';$objects[$smaskObj]='<< /Type /XObject /Subtype /Image /Width '.(int)$im['w'].' /Height '.(int)$im['h'].' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms '.$dp.' /Length '.strlen($im['smask'])." >>\nstream\n".$im['smask']."\nendstream";}
            $obj=$next++; $dp=''; if($im['filter']==='FlateDecode'){$colors=isset($im['colors'])?(int)$im['colors']:($im['cs']==='DeviceRGB'?3:1);$dp=' /DecodeParms << /Predictor 15 /Colors '.$colors.' /BitsPerComponent '.(int)$im['bpc'].' /Columns '.(int)$im['w'].' >>';}
            $sm=$smaskObj?' /SMask '.$smaskObj.' 0 R':'';
            $objects[$obj]='<< /Type /XObject /Subtype /Image /Width '.(int)$im['w'].' /Height '.(int)$im['h'].' /ColorSpace /'.$im['cs'].' /BitsPerComponent '.(int)$im['bpc'].' /Filter /'.$im['filter'].$dp.$sm.' /Length '.strlen($im['data'])." >>\nstream\n".$im['data']."\nendstream";
            $xobjects['/'.$im['name']]=$obj.' 0 R'; $this->images[$k]['obj']=$obj; $this->images[$k]['smask_obj']=$smaskObj;
        }
        $xres=$xobjects?' /XObject << '.implode(' ',array_map(function($n,$v){return $n.' '.$v;},array_keys($xobjects),array_values($xobjects))).' >>':'';
        foreach($this->pages as $content){
            $pageObj=$next++; $contentObj=$next++; $kids[]=$pageObj.' 0 R';
            $objects[$contentObj]='<< /Length '.strlen($content).' >>' . "\nstream\n".$content."endstream";
            $objects[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.sprintf('%.3F',$this->w*$this->k).' '.sprintf('%.3F',$this->h*$this->k).'] /Resources << /Font << /F1 '.$font1.' 0 R /F2 '.$font2.' 0 R >>'.$xres.' >> /Contents '.$contentObj.' 0 R >>';
        }
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$pageCount.' >>';
        ksort($objects); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=array(0=>0); $max=max(array_keys($objects));
        for($i=1;$i<=$max;$i++){if(!isset($objects[$i]))$objects[$i]='<<>>';$offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".$objects[$i]."\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."\n";
        $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";return $pdf;
    }
    public function Output($dest='I',$name='doc.pdf',$isUTF8=false)
    {
        $pdf=$this->buildPdf(); $dest=strtoupper((string)$dest);
        if($dest==='S')return $pdf;
        if(!headers_sent()){
            header('Content-Type: application/pdf');
            header('Content-Length: '.strlen($pdf));
            header('Content-Disposition: '.($dest==='D'?'attachment':'inline').'; filename="'.basename($name).'"');
            header('Cache-Control: private, max-age=0, must-revalidate');
        }
        echo $pdf; return '';
    }
}
