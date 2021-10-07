<?php

/**
 * 繼承 ipdb reader 擴充部分函式
 */

namespace ipip\db;


class RdmReader extends Reader
{
    /**
     * IPv4 bits 長度
     */
    const IPV4_BIT_LEN =  32;
    
    /**
     * IPv6 bits 長度
     */
    const IPV6_BIT_LEN =  128;

     /**
     * @author Joseph_Li
     * Traverse all ipdb database and export txt by ip ranges
     * 針對左右節點執行 二元樹的深度優先搜尋 (DFS, pre-order)
     */
    public function traverse(string $export_file, $ip_type = self::IPV4) {
        $start_ip = $ip_type === self::IPV4 ? '0.0.0.0' : '0::';

        $node_offset = $this->findNodeOffset( $start_ip);

        // prepare the exporting destination
        if(!file_exists($export_file)) {
            @mkdir(dirname($export_file), 0644, true);
        } else {
            @unlink($export_file);
        }

        // 添加 header column name
        switch($ip_type) {
            case self::IPV4:
                $ip_headers = ["start_ip", "end_ip"];
                break;
            case self::IPV6:
                $ip_headers = ["ip"];
                break;
            default:
                throw new \Exception("Unkown ip_type");
        }

        file_put_contents($export_file, implode("\t", [... $ip_headers, ...$this->meta['fields']]). PHP_EOL);
        
        $this->exportFile = fopen($export_file, 'a+');
        $this->readRootIndexNode($node_offset, $ip_type);
        @fclose($this->exportFile);
    }

    /**
     * 取得 ipv4 或是 ipv6 起始的節點 id
     */
    public function findNodeOffset($ip) {
        static $v4offset = 0;

        $binary = inet_pton($ip);
        $bitCount = strlen($binary) * 8; // 32 | 128
        $node = 0;
    
        if ($bitCount === 32)
        {
            if ($v4offset === 0)
            {
                for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++)
                {
                    if ($i >= 80)
                    {
                        $idx = 1;
                    }
                    else
                    {
                        $idx = 0;
                    }
                    $node = $this->readNode($node, $idx);
                    if ($node > $this->nodeCount)
                    {
                        return 0;
                    }
                }
                $v4offset = $node;
            }
            else
            {
                $node = $v4offset;
            }
        }
        
        return $node;
    }

    
    /**
     * 由根節點向下查詢
     */
    private function readRootIndexNode($root_node, bool $ip_type) {
        $ip_bits = [];
        $this->readIndexNode($root_node, $ip_bits, "CN", $ip_type);
    }

    /**
     * 讀取左右索引節點 (前 4 bytes 為左子節點, 後 4 bytes 為右子節點)
     * @param int $node Node id
     * @param int[] $ip_bits 存放二進位 ip 陣列 (第一個元素作為 ip 最大 bit (Big-Endian))
     * @return [string, string]
     * 
     * @throws \Exception
     */
    private function readIndexNode(int $node, array $ip_bits, $language = "CN", $ip_type = self::IPV4)
    {
        
        if ($node === $this->nodeCount)
        {
            $data_node = null;
            return $data_node;
        }
        elseif ($node > $this->nodeCount)
        {
            // 當 node id 大於 meta data 中的 node_count 時即為資料節點, 查詢資料節點
            $data = $this->resolve($node);
            $values = explode("\t", $data);
            $data_node = array_slice($values, $this->meta['languages'][$language], count($this->meta['fields']));

            // 寫檔案       
            $ip_rows = [];

            // IPv4 and v6 的 IP 欄位定義不同
            if($ip_type === self::IPV4) {
                $ip_from = $this->getMinSubnetIpBits($ip_bits, self::IPV4_BIT_LEN);
                $ip_to = $this->getMaxSubnetIpBits($ip_bits, self::IPV4_BIT_LEN);

                $ip_rows = [
                    inet_ntop($this->ipBits2Binary($ip_from)), 
                    inet_ntop($this->ipBits2Binary($ip_to)), 
                ];
            } else if ($ip_type === self::IPV6){
                $prefix = count($ip_bits);
                $ip_from = $this->getMinSubnetIpBits($ip_bits, self::IPV6_BIT_LEN);
                $ip_rows = [
                    sprintf("%s%s",  
                        inet_ntop($this->ipBits2Binary($ip_from)), // ipv6 位址
                       "/{$prefix}",      // prefix
                    ),
                   
                ];
            }
           
            $segment = [
                ...$ip_rows,
                ... $data_node
            ];
            
            fwrite($this->exportFile, implode("\t", $segment) . PHP_EOL);
            return $data_node;
        }

       
        $left_child_node = $this->readNode($node, 0);  // 讀取索引節點左半邊
        $right_child_node = $this->readNode($node, 1);  // 讀取索引節點右半邊

        
        $left_child_bits = $this->addIpBits($ip_bits, 0);    // 為 ip_bits 加上 0
        $right_child_bits = $this->addIpBits($ip_bits, 1);  // 為 ip_bits 加上 1
       

        // 先往左子節點查詢
        $this->readIndexNode($left_child_node, $left_child_bits, $language, $ip_type);   

        // 再查詢右子節點
        $this->readIndexNode($right_child_node, $right_child_bits, $language, $ip_type);  

        return [$left_child_node, $right_child_node];
    }

    private function addIpBits(array $ip_bits, int $next_bit) {
        $ip_bits[] = $next_bit;
        return $ip_bits;
    }


    /**
     * 取得子網路中最大 IP
     */
    private function getMaxSubnetIpBits(array $ip_bits, int $length = self::IPV4_BIT_LEN) {
        return array_pad($ip_bits, $length, 1);
    }

    /**
     * 取得子網路中最小 IP
     */
    private function getMinSubnetIpBits(array $ip_bits, int $length = self::IPV4_BIT_LEN) {
        return array_pad($ip_bits, $length, 0);
    }
    /**
     * 將 IP bits 轉換成二進位
     */
    private function ipBits2Binary(array $ip_bits) {
        if (!in_array(count($ip_bits), [self::IPV4_BIT_LEN, self::IPV6_BIT_LEN])) {
            throw new \Exception("ip bits 長度有問題");
        }

        $ip_bytes = array_chunk($ip_bits, 8);
        $hexbin = '';
        foreach($ip_bytes as $byte) {
            $val = 0;
            foreach($byte as $bit) {
                $val = ($val << 1) + $bit;
            }
            $hexbin .= chr($val);
        }

        return $hexbin;
    }

}
