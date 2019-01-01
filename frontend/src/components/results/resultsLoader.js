


export default class ResultsLoader {

    constructor() {
        this.queue=[];
    }

    popQueue() {
        let oldQueue=this.queue;
        this.queue=[];
        return oldQueue;
    }
    cancelLoading() {
        if (this.reader) {
            this.reader.cancel('ABORTED')
        }
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

    async loadUrl(url, params) {
        console.warn(url);
        let response = await fetch(url)
        const body = response.body
        this.reader = body.getReader();
        let decoder= new TextDecoder("utf-8");
        let currentLine="";
        do {
            try {
                const result = await this.reader.read();
                if (result.done) {
                    console.warn("ResultsLoader: finished loading")
                    this.parseLine(currentLine);
                    this.reader=null;
                    return;
                }
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
                await this.sleep(10);
            }catch(e) {
                console.warn("ResultsLoader: error in fetch",e);
            }
        } while(true);
    }
}

