import React from "react";

import ResultsTable from "./ResultsTable.jsx";
import ReactEcharts from 'echarts-for-react';
import 'echarts/lib/chart/bar';
import moment from 'moment';
import {withStyles} from "@material-ui/core";

const styles = {
  root: {
    display: 'flex',
    flexDirection: 'column',
    flexGrow: 1
  },
  table: {
    flexGrow: 1,
    width: '100%'
  },
  chart: {
    height: '200px',
    width: '100%'
  }
}
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

    //console.warn(this.results.histogram.values);
    let option = {
      tooltip: {},
      xAxis: [{
        time: 'time',
        splitNumber:10,
        data: results.histogram.times,
        axisLabel: {
          formatter: x=> {
            return moment(x).format("HH:mm:ss");
          }
        }
      }],
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
    const { results, classes } = this.props;

    if (!results || !results.schema) {
      return null;
    }

    return (
      <div className={classes.root}>
        {
          results.schema.heatmap &&
            <div className={classes.chart}>
              <ReactEcharts
                option={this.state.option}
                style={{height: '200px', width: '100%'}}
                onEvents={this.onChartsEvents}
                notMerge={true}
                lazyUpdate={true}/>
            </div>
        }
        <div className={classes.table}>
          <ResultsTable ref={this.resultsTable} results={results}></ResultsTable>
        </div>
      </div>
    );
  }
}


export default withStyles(styles)(ResultsViewer);





