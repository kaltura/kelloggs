


export default class ResultsLoader {

    constructor() {
        this.queue=[];
        this.error=null;
        this.completed=false;
        this.cancel=false;
        this.abortController = new AbortController()

    }

    popQueue() {
        let oldQueue=this.queue;
        this.queue=[];
        return oldQueue;
    }
    cancelLoading() {
        this.cancel=true;
        console.warn("aborting fetch...")
        this.abortController.abort();
    }

    parseLine(line) {
        try {
            //console.warn(line);
            let o=JSON.parse(line);
            this.queue.push(o);
        }catch(e) {
            console.warn("ResultsLoader: Coudlnt parse line ",line, " error=",e)
        }
    }

    sleep(time) {
        return new Promise( (s,r)=>{
            setTimeout(s,time);
        });
    }

    _buildUrl(serviceUrl, jwt) {
      return `${serviceUrl}?jwt=${jwt}`;
    }

    async loadUrl(serviceUrl, jwt, params) {
        const url = this._buildUrl(serviceUrl, jwt, params);

        console.warn("calling ",url," " ,params);
        let response=null;
        try {
            response = await fetch(url, {
                method: "post",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    filter: params,
                    responseFormat: 'json'
                }),
                signal: this.abortController.signal
            });
        }catch(e) {
            this.queue.push({ "type": "error", "message": e.message});
            return;
        }
        console.warn("got response from ",url," " ,params, " response: ",response);
        if (response.status!==200) {

            this.queue.push({ "type": "error", "message": response.statusText});
            this.completed=true;
            return;
        }
        const body = response.body;
        this.reader = body.getReader();
        let decoder= new TextDecoder("utf-8");
        let currentLine="";
        do {
            try {
                const result = await this.reader.read();
                let buffer=result.value;
                let newText=decoder.decode(buffer);
                //console.warn("ResultsLoader: adding #",buffer.length, " bytes")
                let startIndex=0;
                while(true) {
                    let index=newText.indexOf('\n',startIndex)
                    if (index>-1) {
                        const newLine=newText.substring(startIndex, index);
                        //console.warn("ResultsLoader: found  newline at ",index,newLine);

                        this.parseLine(currentLine+newLine);
                        currentLine="";

                        startIndex=index+1;
                        if (index>=newText.length) {
                            break;
                        }
                        continue;
                    } else {
                        currentLine+=newText.substring(startIndex);
                        //console.warn("ResultsLoader: leftfover:  ",currentLine.length);
                        break;
                    }
                }
                if (result.done) {
                    break;
                }
                await this.sleep(10);
            }catch(e) {
                this.error=e;
                console.warn("ResultsLoader: error in fetch",e);
                break;
            }
        } while(!this.cancel);
        console.warn("ResultsLoader: finished loading")
        if (currentLine.length>0) {
            this.parseLine(currentLine);
        }
        this.reader=null;
        this.completed=true;
    }
}

