import * as React from 'react';
import { AutoSizer, Table,Column} from 'react-virtualized';
import 'react-virtualized/styles.css';
import './ResultsTable.css';


export default class ResultsTable extends React.PureComponent {

  constructor(props) {
    super(props);
    this.results=props.results;
    this.table = React.createRef();

    this.severityOptions=  this.results.getField("severity").options;

    this.state = {
      overscanRowCount: 20,
      rowCount: props.results.items.length,
      scrollToIndex: undefined
    };


    this._noRowsRenderer = this._noRowsRenderer.bind(this);
    this._textCellRenderer = this._textCellRenderer.bind(this);
    this._getRowClassName = this._getRowClassName.bind(this);
    this._severityCellRenderer = this._severityCellRenderer.bind(this);
    this._indexCellRenderer = this._indexCellRenderer.bind(this);
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

    return (

        <div >
          <AutoSizer disableHeight>
            {({width}) => {
              return <Table
                className="results-table"
                rowClassName={this._getRowClassName}
                headerClassName="results-header"
                ref={this.table}
                height={800}
                headerHeight={30}
                overscanRowCount={overscanRowCount}
                noRowsRenderer={this._noRowsRenderer}
                rowCount={rowCount}
                rowHeight={this._getRowHeight}
                scrollToIndex={scrollToIndex}
                rowGetter={({ index }) => this.results.items[index]}
                width={width}>
                  {
                    this.results.schema.fields.map(element =>{
                      let cellRenderer=this._getCellRenderer(element);
                      return <Column
                          label={element.label ? element.label : element.key}
                          dataKey={element.key}
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
      case "text": return this._textCellRenderer;
      case "index": return this._indexCellRenderer;
      default: return undefined;
    }
  }

  //cellRenderer={this._textCellRenderer}
  //flexGrow={1}

  _severityCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {

    let color = this.severityOptions[cellData];

    return <div style={{...style, "backgroundColor": color, position: "absolute", width: "5px", bottom:"3px",top: "3px"}}>
            </div>
  }

  _indexCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {
    return <span style={{...style}}>{rowIndex}</span>
  }

  _getRowHeight({index} ){
    return this.results.items[index].lines*13;
  }
  _textCellRenderer({ cellData,columnIndex, key, parent, rowIndex, style }) {
    return  <span style={{...style, "userSelect": "text",  "whiteSpace": "pre"}}>
              {cellData}
            </span>
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
    if (item.severity=== "ERR") {
      ret+=" results-row-error";
    }
    return ret;

  }

}
