<?php
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
//
// @Author Karthik Tharavaad
//         karthik_tharavaad@yahoo.com
// @Contributor Maurice Svay
//              maurice@svay.Com

namespace svay;

class FaceDetector {
    
    protected $detection_data;
    protected $canvas;
    protected $face;
    private $reduced_canvas;
    private $max_duration;
    public function __construct($detection_file = 'detection.dat', $max_duration = 5) {
        if (is_file($detection_file)) {
            $this->detection_data = unserialize(file_get_contents($detection_file));
        } else {
            throw new Exception("Couldn't load detection data");
        }
        $this->max_duration = $max_duration;
    }
    
    public function faceDetect($file) {
        if (is_resource($file)) {
            
            $this->canvas = $file;
            
        } elseif (is_file($file)) {
            
            $this->canvas = imagecreatefromjpeg($file);
            
        } else {
            
            throw new Exception("Can not load $file");
        }
        
        $im_width  = imagesx($this->canvas);
        $im_height = imagesy($this->canvas);
        
        //Resample before detection?
        $ratio       = 0;
        $diff_width  = 320 - $im_width;
        $diff_height = 240 - $im_height;
        if ($diff_width > $diff_height) {
            $ratio = $im_width / 320;
        } else {
            $ratio = $im_height / 240;
        }
        
        if ($ratio != 0) {
            $this->reduced_canvas = imagecreatetruecolor($im_width / $ratio, $im_height / $ratio);
            imagecopyresampled($this->reduced_canvas, $this->canvas, 0, 0, 0, 0, $im_width / $ratio, $im_height / $ratio, $im_width, $im_height);
            
            $stats = $this->getImgStats($this->reduced_canvas);
            
            $this->face = $this->doDetectGreedyBigToSmall($stats['ii'], $stats['ii2'], $stats['width'], $stats['height']);
            
            if ($this->face['w'] > 0) {
                $this->face['x'] *= $ratio;
                $this->face['y'] *= $ratio;
                $this->face['w'] *= $ratio;
            }
        } else {
            $stats = $this->getImgStats($this->canvas);
            
            $this->face = $this->doDetectGreedyBigToSmall($stats['ii'], $stats['ii2'], $stats['width'], $stats['height']);
        }
        return ($this->face['w'] > 0);
    }
    
    
    public function toJpeg($file) {
        $color = imagecolorallocate($this->canvas, 255, 0, 0); //red
        
        imagerectangle($this->canvas, 
			           $this->face['x'], 
			           $this->face['y'], 
			           $this->face['x'] + $this->face['w'], 
			           $this->face['y'] + $this->face['w'], 
			           $color);
        
        if ($file == false) {
            header('Content-type: image/jpeg');
            imagejpeg($this->canvas);
        } else {
            imagejpeg($this->canvas, $file);
        }
        return $this->face['w'];
    }
    
    public function toJson() {
        return json_encode($this->face);
    }
    
    public function getFace() {
        return $this->face;
    }
    
    protected function getImgStats($canvas) {
        $image_width  = imagesx($canvas);
        $image_height = imagesy($canvas);
        $iis          = $this->computeII($canvas, $image_width, $image_height);
        return array(
            'width' => $image_width,
            'height' => $image_height,
            'ii' => $iis['ii'],
            'ii2' => $iis['ii2']
        );
    }
    
    protected function computeII($canvas, $image_width, $image_height) {
        $ii_w = $image_width + 1;
        $ii_h = $image_height + 1;
        $ii   = array();
        $ii2  = array();
        
        for ($i = 0; $i < $ii_w; $i++) {
            $ii[$i]  = 0;
            $ii2[$i] = 0;
        }
        
        for ($i = 1; $i < $ii_h - 1; $i++) {
            $ii[$i * $ii_w]  = 0;
            $ii2[$i * $ii_w] = 0;
            $rowsum          = 0;
            $rowsum2         = 0;
            for ($j = 1; $j < $ii_w - 1; $j++) {
                $rgb   = ImageColorAt($canvas, $j, $i);
                $red   = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue  = $rgb & 0xFF;
                $grey  = (0.2989 * $red + 0.587 * $green + 0.114 * $blue) >> 0; // this is what matlab uses
                $rowsum += $grey;
                $rowsum2 += $grey * $grey;
                
                $ii_above = ($i - 1) * $ii_w + $j;
                $ii_this  = $i * $ii_w + $j;
                
                $ii[$ii_this]  = $ii[$ii_above] + $rowsum;
                $ii2[$ii_this] = $ii2[$ii_above] + $rowsum2;
            }
        }
        return array(
            'ii' => $ii,
            'ii2' => $ii2
        );
    }
    
    protected function doDetectGreedyBigToSmall($ii, $ii2, $width, $height) {
        $s_w            = $width / 20.0;
        $s_h            = $height / 20.0;
        $start_scale    = $s_h < $s_w ? $s_h : $s_w;
        $scale_update   = 1 / 1.2;
        $startTimestamp = time();
        for ($scale = $start_scale; $scale > 1; $scale *= $scale_update) {
            $actualTimestamp = time();
            if ($actualTimestamp - $startTimestamp - $this->max_duration) { //<<<< NEW line!
                $w        = (20 * $scale) >> 0;
                $endx     = $width - $w - 1;
                $endy     = $height - $w - 1;
                $step     = max($scale, 2) >> 0;
                $inv_area = 1 / ($w * $w);
                for ($y = 0; $y < $endy; $y += $step) {
                    for ($x = 0; $x < $endx; $x += $step) {
                        $passed = $this->detectOnSubImage($x, $y, $scale, $ii, $ii2, $w, $width + 1, $inv_area);
                        if ($passed) {
                            //echo 'var';
                            return array(
                                'x' => $x,
                                'y' => $y,
                                'w' => $w
                            );
                            
                        }
                    } // end x
                } // end y
            } else { //<<<< NEW line!
                break; //<<<< NEW line!
            } //<<<< NEW line!
            
        } // end scale
        
        return null;
    }
    
    protected function detectOnSubImage($x, $y, $scale, $ii, $ii2, $w, $iiw, $inv_area) {
        $mean = ($ii[($y + $w) * $iiw + $x + $w] + $ii[$y * $iiw + $x] - $ii[($y + $w) * $iiw + $x] - $ii[$y * $iiw + $x + $w]) * $inv_area;
        
        $vnorm = ($ii2[($y + $w) * $iiw + $x + $w] + $ii2[$y * $iiw + $x] - $ii2[($y + $w) * $iiw + $x] - $ii2[$y * $iiw + $x + $w]) * $inv_area - ($mean * $mean);
        
        $vnorm = $vnorm > 1 ? sqrt($vnorm) : 1;
        
        $passed = true;
        for ($i_stage = 0; $i_stage < count($this->detection_data); $i_stage++) {
            $stage = $this->detection_data[$i_stage];
            $trees = $stage[0];
            
            $stage_thresh = $stage[1];
            $stage_sum    = 0;
            
            for ($i_tree = 0; $i_tree < count($trees); $i_tree++) {
                $tree         = $trees[$i_tree];
                $current_node = $tree[0];
                $tree_sum     = 0;
                while ($current_node != null) {
                    $vals        = $current_node[0];
                    $node_thresh = $vals[0];
                    $leftval     = $vals[1];
                    $rightval    = $vals[2];
                    $leftidx     = $vals[3];
                    $rightidx    = $vals[4];
                    $rects       = $current_node[1];
                    
                    $rect_sum = 0;
                    for ($i_rect = 0; $i_rect < count($rects); $i_rect++) {
                        $s    = $scale;
                        $rect = $rects[$i_rect];
                        $rx   = ($rect[0] * $s + $x) >> 0;
                        $ry   = ($rect[1] * $s + $y) >> 0;
                        $rw   = ($rect[2] * $s) >> 0;
                        $rh   = ($rect[3] * $s) >> 0;
                        $wt   = $rect[4];
                        
                        $r_sum = ($ii[($ry + $rh) * $iiw + $rx + $rw] + $ii[$ry * $iiw + $rx] - $ii[($ry + $rh) * $iiw + $rx] - $ii[$ry * $iiw + $rx + $rw]) * $wt;
                        
                        $rect_sum += $r_sum;
                    }
                    
                    $rect_sum *= $inv_area;
                    
                    $current_node = null;
                    
                    if ($rect_sum >= $node_thresh * $vnorm) {
                        
                        if ($rightidx == -1) {
                            
                            $tree_sum = $rightval;
                            
                        } else {
                            
                            $current_node = $tree[$rightidx];
                            
                        }
                        
                    } else {
                        
                        if ($leftidx == -1) {
                            
                            $tree_sum = $leftval;
                            
                        } else {
                            
                            $current_node = $tree[$leftidx];
                        }
                    }
                }
                
                $stage_sum += $tree_sum;
            }
            if ($stage_sum < $stage_thresh) {
                return false;
            }
        }
        return true;
    }
}
?>