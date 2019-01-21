import * as React from 'react';
import {AutoSizer, MultiGrid} from 'react-virtualized';
import 'react-virtualized/styles.css';
import ToolTip from '@material-ui/core/Tooltip'
import RichTextView from './RichTextView'
import IconButton from "@material-ui/core/IconButton/IconButton";
import MoreVertIcon from "@material-ui/core/SvgIcon/SvgIcon";
import CommandsMenu from '../CommandsMenu';

const styles = {
    table: {
        flexGrow: 1,
        width: "calc(100%-20px)",
        overflow: "hidden",
        marginLeft: "10px",
        marginRight: "10px"
    },
    grid: {
        fontFamily: "lucida console",
        fontSize: "12px",
        textAlign: "left",
        backgroundColor: "#fff",
        color: "black"
    },
    row: {
        display: "flex",
        borderTop: "1px solid #D9D9D9",
        alignItems: "normal",
        paddingTop: "2px"
    },
    headerRow: {
        backgroundColor: "#eee"
    }
};

export default class ResultsTable extends React.PureComponent {

    constructor(props) {
        super(props);
        this.results = props.results;
        this.table = React.createRef();
        this.firstUpdate=true;

        this.visibleColumns = [];

        this.state = {
            overscanRowCount: 20,
            defaultColumnWidth: 80,
            completed: false,
            rowCount: props.results.items.length,
            scrollToIndex: undefined
        };


        this._cellRenderer = this._cellRenderer.bind(this);
        this._getRowHeight = this._getRowHeight.bind(this);
    }

    scrollTo(index) {
        console.warn("scrolling to ", index)
        this.table.current.scrollToRow(index);
    }

    update(completed) {
        this.setState((oldState) => {
            return {
                completed: completed,
                rowCount: this.results.items.length
            }
        });
        if ( this.firstUpdate) {
            this.table.current.recomputeGridSize(0,0);
            this.firstUpdate=false;
        }
        //this.table.current.scrollToRow(props.context.items.length);
    }

    render() {
        const {
            overscanRowCount,
            rowCount,
            scrollToIndex,
            defaultColumnWidth
        } = this.state;

        this.visibleColumns = this.results.schema.columns.filter(column => !column.hidden);

        let totalWidth = this.visibleColumns.reduce((acc, column) => {
            return acc + (column.width ? column.width : defaultColumnWidth);
        }, 0);

        return (
            <div style={styles.table}>
                <AutoSizer>
                    {({width, height}) => {
                        return <MultiGrid
                            style={styles.grid}
                            styleBottomRightGrid={{ outline: 'none' }}
                            className="results-table"
                            headerClassName="results-header"
                            ref={this.table}
                            fixedRowCount={1}
                            columnWidth={totalWidth}
                            columnCount={1}
                            height={height}
                            cellRenderer={this._cellRenderer}
                            overscanRowCount={overscanRowCount}
                            rowCount={rowCount === 0 ? 2 : rowCount + 1}
                            rowHeight={this._getRowHeight}
                            scrollToIndex={scrollToIndex}
                            maxWidth={3000}
                            width={width}>
                        </MultiGrid>
                    }}
                </AutoSizer>
            </div>
        );
    }


    _getRowHeight({index}) {
        if (index === 0) {
            return 20;
        }
        if (this.state.rowCount === 0 && index === 1) {
            return 30;
        }
        return this.results.items[index - 1].lines * 12 + 4;
    }

    _cellRenderer({columnIndex, key, rowIndex, style}) {

        let left = 0;
        let height = style.height - 7;
        let rowStyle = {...style, ...styles.row}
        if (rowIndex === 0) {
            rowStyle = {...rowStyle, ...styles.headerRow}
        }

        return <div key={key} style={rowStyle}>
            {
                (this.state.rowCount === 0 && rowIndex === 1) ?
                    (this.state.completed ?
                        <div>No results!</div>
                        :
                        <div>Searching...</div>)
                    :
                    this.visibleColumns.map((column) => {

                        let cellStyle = {
                            left: left + "px",
                            width: (column.width || this.state.defaultColumnWidth) + "px",
                            overflow: "hidden",
                            padding: "2px",
                            position: "absolute",
                            height: height
                        }
                        left += column.width ? column.width : this.state.defaultColumnWidth;
                        left += 13;
                        if (rowIndex === 0) {
                            return this._headerRenderer({column, cellStyle})
                        }

                        let row = this.results.items[rowIndex - 1];
                        let value = row[column.name];

                        let fn = this._textCellRenderer;

                        switch (column.type) {
                            case "severity":
                                fn = this._severityCellRenderer;
                                break;
                            case "timestamp":
                                fn = this._timestampCellRenderer;
                                break;
                            case "float":
                                fn = this._floatCellRenderer;
                                break;
                            case "richText":
                                fn = this._richTextCellRenderer;
                                break;
                            case "commands":
                                fn = this._commandsCellRenderer;
                                break;
                            /*
                            case "index": return this._indexCellRenderer;
                            case "text": return this._textCellRenderer;
                            default: return undefined;*/
                        }
                        return fn({key, column, value, cellStyle});
                    })
            }
        </div>

    }


    _headerRenderer({column, cellStyle}) {

        return <ToolTip title={column.label ? column.label : column.name}>
            <div style={{...cellStyle, "whiteSpace": "pre"}}>
                {column.label ? column.label : column.name}
            </div>
        </ToolTip>
    }

    _handleCommandsClick() {

    }

    _commandsCellRenderer({column, value, cellStyle}) {
        if (value && value.length > 0) {
            return <div style={{...cellStyle, width: "5"}}>
                <CommandsMenu type={'text'} commands={value} showBadge={false}></CommandsMenu>
            </div>
        }
        return null
    }


    _severityCellRenderer({column, value, cellStyle}) {

        let color = column.options[value];

        return <div style={{...cellStyle, "backgroundColor": color, width: "2px"}}>
        </div>
    }

    _timestampCellRenderer({column, value, cellStyle}) {
        return <span style={{...cellStyle}}>{value.format('YYYY/MM/DD HH:mm:ss')}</span>
    }

    _indexCellRenderer({column, value, cellStyle}) {
        return <span style={{...cellStyle}}>{value}</span>
    }

    _floatCellRenderer({column, value, cellStyle}) {

        let color = "black";
        let levels = column.levels;
        if (value > levels[0]) {
            color = "orange"
        }
        if (value > levels[1]) {
            color = "red"
        }
        return <span style={{...cellStyle, userSelect: "text", whiteSpace: "pre", color: color}}>
          {value}
          </span>
    }


    _textCellRenderer({column, value, cellStyle}) {
        return <ToolTip placement={'left'} enterDelay={1000} title={value}>
              <span style={{...cellStyle, userSelect: "text", whiteSpace: "pre"}}>
                {value}
              </span>
        </ToolTip>
    }


    _richTextCellRenderer({column, value, cellStyle}) {

        return <RichTextView indent={0} style={{...cellStyle, "userSelect": "text", "whiteSpace": "pre"}} data={value}>
            {value}
        </RichTextView>
    }

}
