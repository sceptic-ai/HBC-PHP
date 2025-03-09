<?php

class User{
  public $posts = array();
  public $postCount = 0;
  public $user_id;
  public $name;

  public function appendPost($post){
    array_push($this->posts, $post->id);
    $this->postCount++;
  }

  public function initializeUser($post){
    $this->user_id = $post->from_id;
    $this->name = $post->from_name;
    $this->appendPost($post);
  }

  private function inRange($timestamp, $range){ if( $timestamp >= $range->start && $timestamp < $range->end ) return true; else return false; }

  private function seekStart($range,$allPosts){
    for( $i=0; $i<$this->postCount; $i++ ){
      $key = $this->posts[$i];
      $currentTimestamp = $allPosts[ $key ]->timestamp;
      if( $currentTimestamp >= $range->start && $currentTimestamp < $range->end ) return $i;
      else if( $currentTimestamp > $range->end ) return -1;
    }
    return -1;
  }

  private function averageChars($range,$allPosts){
    $index = $this->seekStart($range,$allPosts);
    if( $index==-1 ) return 0;
    $sum = 0;
    $processedPosts = 0;
    do{
      $post = $allPosts[ $this->posts[$index] ];
      if( $this->inRange($post->timestamp, $range) ){
        $sum += strlen($post->message);
        $processedPosts++;
      }
      $index++;
    } while( $index<$this->postCount && $this->inRange($post->timestamp, $range) );
    return $sum/(float)$processedPosts;
  }

  private function longestPost($range,$allPosts){
    $index = $this->seekStart($range,$allPosts);
    if( $index==-1 ) return 0;
    $maxLength = 0;
    $longestPostId = null;
    do{
      $post = $allPosts[ $this->posts[$index] ];
      if( $this->inRange($post->timestamp, $range) ){
        $postLength = strlen($post->message);
        if( $postLength > $maxLength ){
          $maxLength = $postLength;
          $longestPostId = $post->id;
        }
      }
      $index++;
    } while( $index<$this->postCount && $this->inRange($post->timestamp, $range) );
    $result = [
      "length"=>$maxLength,
      "postId"=>$longestPostId,
      "message"=>$allPosts[$longestPostId]->message
    ];
    return $result;
  }

  private function countPosts($range,$allPosts){
    $index = $this->seekStart($range,$allPosts);
    if( $index==-1 ) return 0;
    $count = 0;
    do{
      $post = $allPosts[ $this->posts[$index] ];
      $index++;
      if( $this->inRange($post->timestamp, $range) ) $count++;
    } while( $index<$this->postCount && $this->inRange($post->timestamp, $range) );
    return $count;
  }

  private function buildWeekStatistics($allPosts, $range){
    $ranges = splitMonthToWeeks($range);
    $output = '';

    $omitComma = true;
    foreach( $ranges as $range ){
      $postsInWeek = $this->countPosts($range,$allPosts);
      $week = (int)date("W",$range->start);
      if( $omitComma ) $omitComma = false;
      else $output .= ',';
      $output .= sprintf( '"%d":%d', $week, (int)$postsInWeek );
    }
    return $output;
  }

  private function buildMonthStatistics($allPosts,$ranges){
    $monthsOutput = '';
    $omitComma = true;
    foreach( $ranges as $range ){
      $longest = $this->longestPost($range,$allPosts);
      $postsInMonth = $this->countPosts($range,$allPosts);
      $weeksOutput =  $this->buildWeekStatistics($allPosts,$range);

      if ( $omitComma ) $omitComma = false;
      else $monthsOutput .= ',';

      $monthsOutput .= sprintf('
        "%s":{
          "average post length":%.2f,
          "number of posts":%d,
          "longest post":{
            "post_id":"%s",
            "length":%d,
            "content":"%s"
          },
          "number of posts per week":{ %s }
        }',
        date("M",$range->start),
        $this->averageChars($range,$allPosts),
        $postsInMonth,
        $longest['postId'],
        $longest['length'],
        $longest['message'],
        $weeksOutput
      );
    }
    return $monthsOutput;
  }

  public function buildStatistics($allPosts,$ranges){
    $monthsOutput = $this->buildMonthStatistics($allPosts,$ranges);
    $userOutput = sprintf( '{
      "name":"%s",
      "total number of posts":%d,
      "average number of posts per month":%.2f,
      "statistics per month":{ %s }
    }',
      $this->name,
      $this->postCount,
      $this->postCount/(float)count($ranges),
      $monthsOutput
    );
    return $userOutput;
  }
}

?>