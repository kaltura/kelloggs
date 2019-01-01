import React from "react";

import ResultsTable from "./ResultsTable.jsx";
import ReactEcharts from 'echarts-for-react';
import 'echarts/lib/chart/bar';
import moment from 'moment';


class ResultsViewer extends React.Component {

  constructor(props) {
    super();
  }

  componentDidMount() {
    const { results } = this.props;

    this.resultsTable = React.createRef()
    this.calculateChart();

    results.cb=()=> {
      if (this.resultsTable.current) {
        this.resultsTable.current.update();
      }
      this.calculateChart();
      //this.table.current.scrollToRow(props.context.items.length);
    }

    let self=this;
    this.onChartsEvents= {
      click(params) {
        console.log(params)
        self.resultsTable.current.scrollTo(self.results.histogram.indexes[params.dataIndex]);
      }
    }
  }

  calculateChart() {
    const { results } = this.props;

    console.warn(results.histogram.times);
    let option = {
      tooltip: {},
      xAxis: [{
        time: 'time',
        splitNumber:10,
        data: results.histogram.times.map(t=>t.toDate()),
        axisLabel: {
          formatter: x=> {
            return moment(x).format("HH:mm:ss");
          }
        }
      }],
      grid: {
          left: 50,
          top: 5,
          right: 20,
          bottom: 30
      },
      yAxis: {},
      series: []
    };

    let options=results.getHistrogramOptions();
    for(let opt in options) {
      option.series.push({
        name: opt,
        type: 'bar',
        stack: "xxx",
        itemStyle: {
          color: options[opt]
        },
        data: results.histogram.values[opt]
      });
    }
    this.setState( { option } );

  }

  state = {
    results: null,
    option: {}
  };

  handleChange = (event, value) => {
    this.setState({ value });
  };

  handleChangeIndex = index => {
    this.setState({ value: index });
  };
  render() {
    const { results } = this.props;

    if (!results || !results.schema) {
      return null;
    }

    return (
      <div style={{"width":"100%", "height":"100%", "position":"absolute"}}>
        {
          results.schema.heatmap ?
           <ReactEcharts
            style={{height: '100px'}}
            option={this.state.option}
            onEvents={this.onChartsEvents}
            notMerge={true}
            lazyUpdate={true}/>
          : ""
        }
        <ResultsTable ref={this.resultsTable} results={results}></ResultsTable>
      </div>
    );
  }
}


export default ResultsViewer;


/*

          <div style={{ height: 500, width: 902 }}>
          </div>

            <LazyLog selectableLines="true" stream="true" follow="true" url="https://gist.githubusercontent.com/helfi92/96d4444aa0ed46c5f9060a789d316100/raw/ba0d30a9877ea5cc23c7afcd44505dbc2bab1538/typical-live_backing.log"/>
*/





