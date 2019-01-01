import * as React from 'react';
import { AutoSizer, Table,Column} from 'react-virtualized';
import 'react-virtualized/styles.css';
import './ResultsTable.css';
import ToolTip from '@material-ui/core/Tooltip'
import RichTextView from './RichTextView'

export default class ResultsTable extends React.PureComponent {

  constructor(props) {
    super(props);
    this.results=props.results;
    this.table = React.createRef();

    this.columnCache={};

    this.state = {
      overscanRowCount: 20,
      rowCount: props.results.items.length,
      scrollToIndex: undefined
    };


    this._noRowsRenderer = this._noRowsRenderer.bind(this);
    this._textCellRenderer = this._textCellRenderer.bind(this);
    this._richTextCellRenderer = this._richTextCellRenderer.bind(this);
    this._getRowClassName = this._getRowClassName.bind(this);
    this._severityCellRenderer = this._severityCellRenderer.bind(this);
    this._indexCellRenderer = this._indexCellRenderer.bind(this);
    this._timestampCellRenderer = this._timestampCellRenderer.bind(this);
    this._floatCellRenderer = this._floatCellRenderer.bind(this);


    this._getRowHeight = this._getRowHeight.bind(this);
  }

  scrollTo(index) {
    console.warn("scrolling to ",index)
    this.table.current.scrollToRow(index);
  }

  update() {

    this.setState((oldState) => {
      return { rowCount: this.results.items.length}
    });
      //this.table.current.scrollToRow(props.context.items.length);
  }

  render() {
    const {
      overscanRowCount,
      rowCount,
      scrollToIndex
    } = this.state;

    let columns = this.results.schema.columns.filter( column=> !column.hidden);

    let totalWidth=columns.reduce( (acc,column)=> {
      return acc+column.width;
    },0);

    return (
        <div style={{flexGrow: 1, width: "100%", overflow: "scroll"}}>
          <AutoSizer>
            {({width,height}) => {
              return <Table
                className="results-table"
                rowClassName={this._getRowClassName}
                headerClassName="results-header"
                ref={this.table}
                height={height}
                headerHeight={20}
                overscanRowCount={overscanRowCount}
                noRowsRenderer={this._noRowsRenderer}
                rowCount={rowCount}
                rowHeight={this._getRowHeight}
                scrollToIndex={scrollToIndex}
                rowGetter={({ index }) => this.results.items[index]}
                width={width}>
                  {
                      columns.map((element,index) =>{
                          let cellRenderer=this._getCellRenderer(element);
                          this.columnCache[index]=this.results.getColumn(element.name);
                          return <Column
                              label={element.label ? element.label : element.name}
                              dataKey={element.name}
                              cellRenderer={cellRenderer}
                              width={element.width}
                          />
                      })
                  }
              </Table>
            }}
          </AutoSizer>
        </div>
    );
  }

  _getCellRenderer(element) {
    switch (element.type) {
      case "severity": return this._severityCellRenderer;
      case "timestamp": return this._timestampCellRenderer;
      case "index": return this._indexCellRenderer;
      case "float": return this._floatCellRenderer;
      case "richText": return this._richTextCellRenderer;
      case "text": return this._textCellRenderer;
      default: return undefined;
    }
  }



  //cellRenderer={this._textCellRenderer}
  //flexGrow={1}

  _severityCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {

    let color =  this.columnCache[columnIndex].options[cellData];

    return <div style={{...style, "backgroundColor": color, position: "absolute", width: "5px", bottom:"3px",top: "3px"}}>
            </div>
  }

  _timestampCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {
        return <span style={{...style}}>{cellData.format('YYYY/MM/DD HH:mm:ss')}</span>
    }

  _indexCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {
    return <span style={{...style}}>{rowIndex}</span>
  }

  _floatCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {

      let color= "black";
      let levels= this.columnCache[columnIndex].levels;
      if (cellData>levels[0]) {
        color="orange"
      }
      if (cellData>levels[1]) {
          color="red"
      }
      return   <span style={{...style, "userSelect": "text",  "whiteSpace": "pre", "color": color}}>
              {
                  cellData
              }
            </span>  }


  _getRowHeight({index} ){
    return this.results.items[index].lines*13;
  }
  _textCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {
    return  <ToolTip key={key} title={cellData}>
              <span style={{...style, "userSelect": "text",  "whiteSpace": "pre"}}>
                {cellData}
              </span>
            </ToolTip>
  }


  _richTextCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {

      let key1=columnIndex+"-"+rowIndex;
      return <RichTextView key={key1} indent={0} style={{...style, "userSelect": "text",  "whiteSpace": "pre"}} data={cellData}>
                {cellData}
             </RichTextView>
    }

  _noRowsRenderer() {
    return <div>No rows</div>;
  }

  _getRowClassName({ index }) {
    if (index<0) {
      return "results-header";
    }
    let item = this.results.items[index];
    let ret=  "results-row";
    if (item.severity=== "debug") {
      ret+=" results-row-error";
    }
    return ret;

  }

}
