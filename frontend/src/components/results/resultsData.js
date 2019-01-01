function roundTime(time,seconds=60) {
    let align=seconds*1000;
    return new Date(Math.round(time.getTime()/align)*align);
}

function char_count(str, letter)
{
    let  letter_Count = 0;
    for (let position = 0; position < str.length; position++)
    {
        if (str.charAt(position) === letter)
        {
            letter_Count += 1;
        }
    }
    return letter_Count;
}




function getLineCount(body){

    return body.reduce ( (count,item)=>{
        let lines=char_count(item.text,'\n');
        return count+lines+1;
    },0);
}

export default  class ResultsData {

    constructor(schema) {

        this.items=[];
        this.cb=null;
        this.histogram =  {
            times: [],
            values: {},
            indexes: []
        }
        this.setSchema(schema);

        let lastItemCount=0;
        setInterval( ()=> {
            if (lastItemCount!==this.items.length) {
                lastItemCount=this.items.length;
                console.warn("added items ",lastItemCount)
                if (this.cb) {
                    this.cb();
                }
            }

        },2000)
    }
    setSchema(schema) {

        this.schema=schema;
        this.schema.heatmap= { key: "severity"};
        this.schema.columns.shift = {
            name: "index",
            type: "index"
        }
        let severityColumn=this.getColumn("severity")
        if (severityColumn) {
            severityColumn.options={
                "error": "red",
                "debug": "blue",
                "info": "green",
                "notice": "cyan"
            }
        }

        let options = this.getHistrogramOptions();
        for(let option in options) {
            this.histogram.values[option]=[];
        }

    }

    getColumn(key) {
        return this.schema.columns.find( field=>  {
            return field.name===key;
        });
    }

    getHistrogramColumn() {
        if (this.schema.heatmap) {
            return this.getColumn(this.schema.heatmap.key);
        }
        return "";

    }

    getHistrogramOptions() {
        let field = this.getHistrogramColumn();
        if (field) {
            return field.options;
        }
        return {"count": "yellow"};
    }

    append(result) {
        if (this.schema.heatmap) {
            let value=this.schema.heatmap.key ? result[this.schema.heatmap.key] : "count";
            this._addToHistogram(roundTime(result.timestamp),value,this.items.length) ;;
        }
        result.lines=getLineCount(result.body);
        this.items.push(result);
    }

    _addToHistogram(key,value,index) {
        try {
            if (this.histogram.times.length===0 || this.histogram.times[this.histogram.times.length-1]<key) {
                this.histogram.times.push(key);

                for(let valueName in this.histogram.values) {
                    this.histogram.values[valueName].push(0);
                }

                this.histogram.indexes.push(index);
            }
            let arr=this.histogram.values[value];
            arr[arr.length-1]++;
        }
        catch (e) {
            console.warn("exception in _addToHistogram",value," ",e);
        }
    }
  }
