<?php

namespace App\Http\Controllers\Api;

use App\Events\PostCommentEvent;
use App\Events\PostLikeEvent;
use App\Events\PostStoreEvent;
use App\Events\PostUpdateEvent;
use App\Libraries\Word;
use App\Listeners\PostUpdateListener;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostCommentLike;
use App\Models\PostHistory;
use App\Models\PostLike;
use App\Models\Project;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PostController extends BaseController
{
    /**
     * @param Request $request
     * @param Post $post
     * @return mixed
     */
    public function detail(Request $request, Post $post){
        $post->comments->each->parent;
        $post->comments->each->likeEmojis;
        $post->parents = $post->parentsEach();
        return $this->success($post);
    }
    
    /**
     * 查询所属父级菜单
     * @param  Project $project
     * @param  int $id
     * @return mixed
     */
    public function parents(Project $project, int $id){
        $Post = new Post();
        $cascader = $Post->childrenEdit($project->id, 0, $id);
        return $this->success($cascader);
    }
    
    /**
     * @param Request $request
     * @param int $id
     * @return mixed
     */
    public function store(Request $request, int $id){
        $post = $request->validate([
            'pid'       => 'required',
            'project_id'=> 'required|integer|min:1',
            'name'      => 'required',
            'content'   => '',
            'html'      => '',
            'sort'      => '',
        ]);
        $post['user_id'] = Auth::id();
        $post['status'] = 1;
        
        $Post = Post::updateOrCreate(['id' => $id], $post);
    
        // 存入记录
        if($post['content'] && $Post->content != $post['content']){
            PostHistory::create([
                'user_id'   => Auth::id(),
                'post_id'   => $Post->id,
                'content'   => $post['content'],
            ]);
        }
        
        // 分发日志记录
        if($id > 0){
            event(new PostUpdateEvent($Post));
        }else{
            event(new PostStoreEvent($Post));
        }
        
        return $this->success($Post);
    }
    
    /**
     * 导出单个文档为 Word
     * @param Post $post
     * @return array
     */
    public function export(Post $post){
        $Word = new Word();
        $url = $Word->addPost($post)->save('exports/'.$post->name.'.doc');
        return $this->success([
            'fileurl' => $url,
        ]);
    }
    
    /**
     * 删除文档
     * @param Post $post
     * @return mixed
     * @throws \Exception
     */
    public function delete(Post $post){
        $post->histories->each->delete();
        $post->attachments->each->delete();
        $post->comments->each->likes->each->delete();
        $post->comments->each->delete();
        $post->likes->each->delete();
        $post->events->each->delete();
        $post->delete();
        return $this->success();
    }
    
    /**
     * 修改历史
     * @param Post $post
     * @return mixed
     */
    public function history(Post $post){
        $Histories = PostHistory::select('id', 'created_at', 'post_id', 'user_id', 'content')->with(['user'])->where(['post_id' => $post->id])->latest()->limit(50)->get();
        return $this->success($Histories);
    }
    
    /**
     * 删除历史
     * @param PostHistory $postHistory
     * @return mixed
     */
    public function history_delete(PostHistory $postHistory){
        $postHistory->delete();
        return $this->success();
    }
    
    /**
     * 文档点赞
     * @param Request $request
     * @param Post $post
     * @return mixed
     */
    public function like(Request $request, Post $post){
        $request->validate([
            'emoji' => 'required',
        ]);
        $PostLike = PostLike::create([
            'user_id'   => Auth::id(),
            'post_id'   => $post->id,
            'emoji'     => $post['emoji'],
        ]);
        
        // 分发日志记录
        event(new PostLikeEvent($PostLike));
        return $this->success($PostLike);
    }
    
    /**
     * 提交评论
     * @param Request $request
     * @param Post $post
     * @return mixed
     */
    public function comment(Request $request, Post $post){
        if (Cache::has('PostController@comment')) {
            return $this->failed('请求频繁');
        }
        Cache::put('PostController@comment', 1, 5);
        $param = $request->validate([
            'pid'       => 'required|integer|min:0',
            'content'   => 'required',
        ]);
        $param['post_id'] = $post->id;
        $param['user_id'] = Auth::id();
        $PostComment = PostComment::create($param);
    
        // 分发日志记录
        event(new PostCommentEvent($PostComment));
        return $this->success($PostComment);
    }
    
    /**
     * 文档评论点赞
     * @param Request $request
     * @param PostComment $postComment
     * @return mixed
     */
    public function comment_like(Request $request, PostComment $postComment){
        $post = $request->validate([
            'emoji' => 'required',
        ]);
        PostCommentLike::create([
            'user_id'       => Auth::id(),
            'post_comment_id'=> $postComment->id,
            'emoji'         => $post['emoji'],
        ]);
        return $this->success();
    }
}
