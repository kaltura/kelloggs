function roundTime(time,seconds=60) {
    let align=seconds*1000;
    return new Date(Math.round(time.getTime()/align)*align);
}

let defaultSchema = {
    heatmap: {
        key: "severity"
    },
    fields: [
        {             
            key: "severity",
            type: "severity",
            width: 5,
            options: {
                "ERR": "red", 
                "DEBUG": "blue",
                "INFO": "green",
                "VERBOSE": "cyan",
                "WARN": 'yellow',
                "NOTICE": 'yellow'
            },
        },
        { 
            key: "index",
            type: "index",
            width: 50
        },
        { 
            key: "time",
            type: "time",
            width: 200
        },
        { 
            key: "category",
            type: "string",
            width: 200
        },
        { 
            key: "text",
            type: "text"
        }
    ]
}

export default  class Results {

    constructor(schema=defaultSchema) {
  
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

        let options = this.getHistrogramOptions();
        for(let option in options) {
            this.histogram.values[option]=[];
        } 
    }

    getField(key) {
        return this.schema.fields.find( field=>  {
            return field.key===key;
        });
    }

    getHistrogramField() {
        if (this.schema.heatmap) {
            return this.getField(this.schema.heatmap.key);
        }
        return "";

    }

    getHistrogramOptions() {
        let field = this.getHistrogramField();
        if (field) {
            return field.options;
        }
        return {"count": "yellow"};
    }

    append(result) {
        if (this.schema.heatmap) {
            let value=this.schema.heatmap.key ? result[this.schema.heatmap.key] : "count";
            this._addToHistogram(roundTime(result.time),value,this.items.length) ;;
        }
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